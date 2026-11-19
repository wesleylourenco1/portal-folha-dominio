<?php

namespace App\Http\Controllers;

use App\Models\LancamentoCompetencia;
use App\Models\TarefaAgente;
use App\Services\DominioTxtLancamentos;
use App\Services\Lancamentos;
use Illuminate\Http\Request;

class LancamentoController extends Controller
{
    public function __construct(private Lancamentos $lancamentos) {}

    private function tela(string $comp, array $erros = [], string $msg = '', array $enviados = [])
    {
        $linhas = $this->lancamentos->carregar($comp);
        foreach ($linhas as &$l) {   // mantém o que foi digitado nas linhas com erro
            if (isset($erros[$l['func']->codigo])) {
                $l['valores'] = $enviados[$l['func']->codigo] ?? $l['valores'];
            }
        }

        $ultima = TarefaAgente::where('tipo', 'importar_lancamentos')->latest('id')->limit(30)->get()
            ->first(fn ($t) => ($t->payload['competencia'] ?? null) === $comp);

        return view('lancamentos.index', [
            'importacao' => $ultima,
            'comp' => $comp, 'linhas' => $linhas, 'colunas' => Lancamentos::COLUNAS, 'erros' => $erros,
            'msg' => $msg, 'st' => LancamentoCompetencia::de($comp), 'tot' => $this->lancamentos->totais($linhas),
        ]);
    }

    public function index(Request $request)
    {
        $comp = preg_match('/^\d{2}\/\d{4}$/', (string) $request->query('comp')) ? $request->query('comp') : now()->format('m/Y');

        return $this->tela($comp);
    }

    /** Arquivo texto no leiaute de importação de lançamentos da Domínio. */
    public function txt(Request $request, DominioTxtLancamentos $gerador)
    {
        $request->validate(['comp' => ['required', 'regex:/^\d{2}\/\d{4}$/']]);
        $comp = $request->query('comp');
        $r = $gerador->gerar($comp);
        if ($r['linhas'] === 0) {
            return $this->tela($comp, msg: 'Nenhum lançamento para gerar o arquivo nesta competência.');
        }
        $nome = 'lancamentos_dominio_'.str_replace('/', '', $comp).'.txt';

        return response($r['conteudo'], 200, [
            'Content-Type' => 'text/plain; charset=windows-1252',
            'Content-Disposition' => "attachment; filename=\"{$nome}\"",
        ]);
    }

    public function salvar(Request $request)
    {
        $request->validate(['comp' => ['required', 'regex:/^\d{2}\/\d{4}$/']]);
        $comp = $request->input('comp');
        $usuario = $request->user()->name;
        $st = LancamentoCompetencia::de($comp);

        if ($request->input('acao') === 'reabrir') {
            $st->fill(['status' => 'aberta', 'por' => $usuario, 'em' => now()])->save();

            return redirect()->route('lancamentos.index', ['comp' => $comp]);
        }
        if ($request->input('acao') === 'enviar_dominio') {
            abort_unless($st->fechada(), 409, 'Feche a competência antes de enviar para a Domínio.');

            return $this->tela($comp, msg: $this->enfileirarImportacao($comp, $usuario));
        }
        if ($st->fechada()) {
            return $this->tela($comp, msg: 'Competência fechada. Reabra para alterar.');
        }

        $enviados = (array) $request->input('v', []);
        if ($erros = $this->lancamentos->salvar($comp, $enviados, $usuario)) {
            return $this->tela($comp, $erros, 'Corrija os campos em vermelho. As demais linhas foram salvas.', $enviados);
        }
        if ($request->input('acao') === 'fechar') {
            $st->fill(['status' => 'fechada', 'por' => $usuario, 'em' => now()])->save();

            return $this->tela($comp, msg: 'Lançamentos salvos e competência fechada. '.$this->enfileirarImportacao($comp, $usuario));
        }

        return $this->tela($comp, msg: 'Lançamentos salvos.');
    }

    /** Coloca na fila do agente a importação do TXT na Domínio (uma por competência por vez). */
    private function enfileirarImportacao(string $comp, string $usuario): string
    {
        $pendente = TarefaAgente::where('tipo', 'importar_lancamentos')->whereIn('status', ['pendente', 'executando'])
            ->get()->first(fn ($t) => ($t->payload['competencia'] ?? null) === $comp);
        if ($pendente) {
            return 'A importação desta competência já está na fila do robô.';
        }
        TarefaAgente::enfileirar('importar_lancamentos', ['competencia' => $comp], $usuario);

        return 'O robô vai importar os lançamentos na Domínio.';
    }
}
