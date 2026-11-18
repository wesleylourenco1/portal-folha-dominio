<?php

namespace App\Http\Controllers;

use App\Models\Aluguel;
use App\Models\TarefaAgente;
use App\Services\DominioTxtNotaAluguel;
use App\Support\Br;
use Illuminate\Http\Request;

/** Aluguel mensal: digitar a correspondência da imobiliária, conferir o IRRF e lançar a nota na Escrita Fiscal. */
class AluguelController extends Controller
{
    public function index(Request $request)
    {
        $comp = $request->query('competencia') ?: now()->subMonth()->format('m/Y');
        $aluguel = Aluguel::firstWhere('competencia', $comp) ?? new Aluguel([
            'competencia' => $comp, 'itens' => [], 'perc_administracao' => 10,
            'data_nota' => \Illuminate\Support\Carbon::createFromFormat('d/m/Y', '01/'.$comp)->endOfMonth()->format('d/m/Y'),
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
        $data = fn ($v) => $v ? \Illuminate\Support\Carbon::createFromFormat('Y-m-d', $v)->format('d/m/Y') : null;
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
        $c = $a->calculo;
        if (! $c || ! $c['valor_produtos'] || $c['base'] === null) {
            return back()->withErrors(['aluguel' => 'Preencha os aluguéis e o IRRF (o portal não achou base que dê esse IRRF).']);
        }
        $cnpj = app(\App\Services\EsocialConsultas::class)->cnpj();
        if (! $cnpj) {
            return back()->withErrors(['aluguel' => 'Informe o CNPJ da empresa em Configurações (eSocial) ou envie uma folha antes.']);
        }
        try {
            $txt = DominioTxtNotaAluguel::gerar($a, $cnpj);
        } catch (\RuntimeException $e) {
            return back()->withErrors(['aluguel' => $e->getMessage()]);
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
        ], $request->user()->name);
        $a->update(['status' => 'na_fila', 'mensagem' => 'Aguardando o robô lançar a nota (EFD-Reinf não será transmitido).']);

        return redirect()->route('alugueis.index', ['competencia' => $a->competencia])->with('msg', 'Enviado ao robô: ele lança a nota na Escrita Fiscal (sem transmitir o EFD-Reinf).');
    }
}
