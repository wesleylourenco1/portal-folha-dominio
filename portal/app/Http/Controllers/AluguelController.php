<?php

namespace App\Http\Controllers;

use App\Models\Aluguel;
use App\Models\TarefaAgente;
use App\Services\DominioTxtNotaAluguel;
use App\Services\EsocialConsultas;
use App\Services\LeitorAluguel;
use App\Services\ReinfStatus;
use App\Support\Br;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/** Aluguel mensal: digitar a correspondência da imobiliária, conferir o IRRF e lançar a nota na Escrita Fiscal. */
class AluguelController extends Controller
{
    public function index(Request $request)
    {
        $comp = $request->query('competencia') ?: now()->subMonth()->format('m/Y');
        $aluguel = Aluguel::firstWhere('competencia', $comp) ?? new Aluguel([
            'competencia' => $comp, 'itens' => [], 'perc_administracao' => 10,
            'data_nota' => Carbon::createFromFormat('d/m/Y', '01/'.$comp)->endOfMonth()->format('d/m/Y'),
        ]);

        return view('alugueis.index', [
            'a' => $aluguel, 'calc' => $aluguel->itens ? $aluguel->calcular() : null,
            'lista' => Aluguel::all()->sortByDesc(fn ($x) => substr($x->competencia, 3).substr($x->competencia, 0, 2)),
            'tarefa' => TarefaAgente::where('tipo', 'lancar_nota_aluguel')->latest('id')->get()
                ->first(fn ($t) => ($t->payload['competencia'] ?? null) === $comp),
        ]);
    }

    public function salvar(Request $request)
    {
        $request->validate([
            'competencia' => ['required', 'regex:/^\d{2}\/\d{4}$/'],
            'data_nota' => 'required|date_format:Y-m-d',
            'vencimento' => 'nullable|date_format:Y-m-d',
        ]);
        $itens = [];
        foreach ((array) $request->input('itens', []) as $i) {
            $valor = Br::valor($i['valor'] ?? '');
            if ($valor > 0 && isset(Aluguel::TIPOS[$i['tipo'] ?? ''])) {
                $itens[] = ['tipo' => $i['tipo'], 'descricao' => trim((string) ($i['descricao'] ?? '')), 'valor' => $valor];
            }
        }
        $data = fn ($v) => $v ? Carbon::createFromFormat('Y-m-d', $v)->format('d/m/Y') : null;
        $a = Aluguel::firstOrNew(['competencia' => $request->input('competencia')]);
        if (in_array($a->status, ['na_fila', 'lancado'], true) && $request->input('acao') !== 'reabrir') {
            return back()->withErrors(['aluguel' => 'Este aluguel já foi enviado para a Domínio; não dá para alterar.']);
        }
        $a->fill([
            'data_nota' => $data($request->input('data_nota')), 'vencimento' => $data($request->input('vencimento')),
            'itens' => $itens, 'irrf_imobiliaria' => Br::valor($request->input('irrf_imobiliaria', '')),
            'valor_boleto' => $request->filled('valor_boleto') ? Br::valor($request->input('valor_boleto')) : null,
            'perc_administracao' => Br::valor($request->input('perc_administracao', '10')) ?: 10,
            'observacoes' => $request->input('observacoes'), 'atualizado_por' => $request->user()->name,
        ]);
        $a->calculo = $itens ? $a->calcular() : null;
        $a->status = 'rascunho';
        $a->save();

        if ($request->input('acao') === 'lancar') {
            return $this->lancar($request, $a);
        }

        return redirect()->route('alugueis.index', ['competencia' => $a->competencia])->with('msg', 'Aluguel salvo.');
    }

    private function lancar(Request $request, Aluguel $a)
    {
        if ($erro = $this->enviarAoRobo($a, $request->user()->name)) {
            return back()->withErrors(['aluguel' => $erro]);
        }

        return redirect()->route('alugueis.index', ['competencia' => $a->competencia])->with('msg', 'Enviado ao robô: ele lança a nota na Escrita Fiscal (sem transmitir o EFD-Reinf).');
    }

    /** EFD-Reinf do mês: "enviar" = Entrega completa na Escrita Fiscal (R-4010 + fechamento R-4099) e conferir; "conferir" só confere. */
    public function reinf(Request $request)
    {
        $request->validate(['competencia' => ['required', 'regex:/^\d{2}\/\d{4}$/'], 'acao' => 'required|in:enviar,conferir']);
        $a = Aluguel::firstWhere('competencia', $request->input('competencia'));
        $voltar = redirect()->route('alugueis.index', ['competencia' => $request->input('competencia')]);
        if (! $a) {
            return $voltar->withErrors(['aluguel' => 'Lance o aluguel desta competência antes da EFD-Reinf.']);
        }
        $acao = $request->input('acao');
        if ($acao === 'enviar' && $a->status !== 'lancado') {
            return $voltar->withErrors(['aluguel' => 'A nota do aluguel ainda não está lançada na Escrita Fiscal.']);
        }
        if (in_array($a->reinf_status, ['na_fila', 'aguardando'], true)) {
            return $voltar->withErrors(['aluguel' => 'O robô já está cuidando da EFD-Reinf deste mês; aguarde.']);
        }
        if ($acao === 'enviar' && $a->reinf_status === 'transmitido') {
            return $voltar->withErrors(['aluguel' => 'A EFD-Reinf deste mês já foi transmitida e conferida.']);
        }
        ReinfStatus::enfileirar($a, $acao === 'enviar' ? 'enviar' : 'conferir', $acao, $request->user()->name);
        $a->update(['reinf_status' => 'na_fila', 'reinf_tentativas' => 0, 'reinf_mensagem' => $acao === 'enviar'
            ? 'Aguardando o robô transmitir a EFD-Reinf (Entrega completa: R-4010 e fechamento R-4099). A DCTFWeb continua com você.'
            : 'Aguardando o robô conferir a EFD-Reinf na Domínio.'] + ($acao === 'enviar' ? ['reinf_enviado_por' => $request->user()->name] : []));

        return $voltar->with('msg', $acao === 'enviar' ? 'Enviado ao robô: ele transmite a EFD-Reinf e confere sozinho.' : 'Enviado ao robô: ele confere a EFD-Reinf.');
    }

    /** Coloca a nota na fila do robô. Devolve a mensagem de erro, ou null se enviou. */
    private function enviarAoRobo(Aluguel $a, string $usuario): ?string
    {
        $c = $a->calculo;
        if (! $c || ! $c['valor_produtos'] || $c['base'] === null) {
            return 'Preencha os aluguéis e o IRRF (o portal não achou base que dê esse IRRF).';
        }
        $cnpj = app(EsocialConsultas::class)->cnpj();
        if (! $cnpj) {
            return 'Informe o CNPJ da empresa em Configurações (eSocial) ou envie uma folha antes.';
        }
        try {
            $txt = DominioTxtNotaAluguel::gerar($a, $cnpj);
        } catch (\RuntimeException $e) {
            return $e->getMessage();
        }
        TarefaAgente::enfileirar('lancar_nota_aluguel', [
            'aluguel_id' => $a->id, 'competencia' => $a->competencia, 'transmitir_reinf' => false,
            'arquivo' => $txt['arquivo'], 'txt_base64' => base64_encode($txt['conteudo']),
            'nota' => [
                'fornecedor' => Aluguel::config('aluguel_fornecedor'), 'acumulador' => Aluguel::config('aluguel_acumulador'),
                'cfop' => Aluguel::config('aluguel_cfop'), 'documento' => $a->documento(),
                'emissao' => $a->data_nota, 'entrada' => $a->data_nota,
                'valor_produtos' => Br::moeda($c['valor_produtos']), 'seguro' => Br::moeda($c['seguro']),
                'valor_contabil' => Br::moeda($c['valor_contabil']),
                'imposto' => Aluguel::config('aluguel_imposto'), 'base' => Br::moeda($c['base']),
                'irrf' => Br::moeda($c['irrf']), 'cod_recolhimento' => Aluguel::config('aluguel_cod_recolhimento'),
            ],
        ], $usuario);
        $a->update(['status' => 'na_fila', 'mensagem' => trim(($a->mensagem ? $a->mensagem.' ' : '').'Aguardando o robô lançar a nota (EFD-Reinf não será transmitido).')]);

        return null;
    }

    /**
     * Print/PDF do e-mail da imobiliária: a IA lê as linhas, o IRRF, o saldo e o vencimento; o portal calcula
     * e, se tudo bater (base que dá o IRRF da imobiliária e saldo = entradas − IRRF), manda o robô lançar sozinho.
     */
    public function lerPrint(Request $request, LeitorAluguel $leitor)
    {
        $request->validate([
            'print' => 'required|array|min:1|max:5',
            'print.*' => 'file|max:10240|mimes:png,jpg,jpeg,webp,pdf',
            'competencia' => ['nullable', 'regex:/^\d{2}\/\d{4}$/'],
        ]);
        $arquivos = array_map(fn ($f) => ['nome' => $f->getClientOriginalName(), 'caminho' => $f->getRealPath()], $request->file('print'));
        try {
            $lido = $leitor->ler($arquivos);
        } catch (\Throwable $e) {
            return back()->withErrors(['aluguel' => 'Não consegui ler o arquivo: '.$e->getMessage()]);
        }

        $comp = $lido['competencia'] ?: $request->input('competencia');
        if (! $comp) {
            return back()->withErrors(['aluguel' => 'A IA não achou o mês do aluguel no arquivo. Abra a competência e envie de novo.']);
        }
        $a = Aluguel::firstOrNew(['competencia' => $comp]);
        if (in_array($a->status, ['na_fila', 'lancado'], true)) {
            return redirect()->route('alugueis.index', ['competencia' => $comp])
                ->withErrors(['aluguel' => "O aluguel de {$comp} já foi enviado para a Domínio; nada foi alterado."]);
        }
        $a->fill([
            'data_nota' => Carbon::createFromFormat('d/m/Y', '01/'.$comp)->endOfMonth()->format('d/m/Y'),
            'vencimento' => $lido['vencimento'], 'itens' => $lido['itens'], 'irrf_imobiliaria' => $lido['irrf'],
            'valor_boleto' => $lido['valor_boleto'], 'perc_administracao' => $a->perc_administracao ?: 10,
            'observacoes' => trim(($lido['imobiliaria'] ? $lido['imobiliaria'].'. ' : '').$lido['observacoes']) ?: $a->observacoes,
            'atualizado_por' => $request->user()->name,
        ]);
        $a->calculo = $a->calcular();
        $a->status = 'rascunho';

        $c = $a->calculo;
        $problemas = [];
        if (! $c['valor_produtos']) {
            $problemas[] = 'nenhuma linha de aluguel';
        }
        if ($c['base'] === null) {
            $problemas[] = 'nenhuma base de cálculo dá o IRRF lido ('.Br::moeda($lido['irrf']).')';
        }
        if ($lido['valor_boleto'] !== null && abs(($c['boleto_calculado'] ?? $c['boleto']) - $lido['valor_boleto']) > 0.02) {
            $problemas[] = 'o saldo do boleto lido ('.Br::moeda($lido['valor_boleto']).') não é entradas − IRRF ('.Br::moeda($c['boleto_calculado'] ?? $c['boleto']).')';
        }
        if ($lido['campos_incertos']) {
            $problemas[] = 'a IA ficou em dúvida em: '.implode(', ', $lido['campos_incertos']);
        }
        $origem = 'Lido do print por IA ('.$lido['_ia'].') em '.now()->format('d/m/Y H:i').'.';
        $a->mensagem = $problemas ? $origem.' Confira antes de lançar: '.implode('; ', $problemas).'.' : $origem;
        $a->save();

        $rota = redirect()->route('alugueis.index', ['competencia' => $comp]);
        if ($problemas || ! $request->boolean('lancar_sozinho')) {
            return $rota->with('msg', $problemas ? 'Print lido, mas não lancei: confira os valores abaixo.' : 'Print lido. Confira e clique em Lançar.');
        }
        if ($erro = $this->enviarAoRobo($a, $request->user()->name)) {
            return $rota->withErrors(['aluguel' => 'Print lido, mas não enviei ao robô: '.$erro]);
        }

        return $rota->with('msg', "Print lido e tudo bateu: nota de {$comp} enviada ao robô (sem EFD-Reinf).");
    }
}
