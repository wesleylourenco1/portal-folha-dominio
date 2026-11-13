<?php

namespace App\Http\Controllers;

use App\Models\LancamentoCompetencia;
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

        return view('lancamentos.index', [
            'comp' => $comp, 'linhas' => $linhas, 'colunas' => Lancamentos::COLUNAS, 'erros' => $erros,
            'msg' => $msg, 'st' => LancamentoCompetencia::de($comp), 'tot' => $this->lancamentos->totais($linhas),
        ]);
    }

    public function index(Request $request)
    {
        $comp = preg_match('/^\d{2}\/\d{4}$/', (string) $request->query('comp')) ? $request->query('comp') : now()->format('m/Y');

        return $this->tela($comp);
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
        if ($st->fechada()) {
            return $this->tela($comp, msg: 'Competência fechada. Reabra para alterar.');
        }

        $enviados = (array) $request->input('v', []);
        if ($erros = $this->lancamentos->salvar($comp, $enviados, $usuario)) {
            return $this->tela($comp, $erros, 'Corrija os campos em vermelho. As demais linhas foram salvas.', $enviados);
        }
        if ($request->input('acao') === 'fechar') {
            $st->fill(['status' => 'fechada', 'por' => $usuario, 'em' => now()])->save();

            return $this->tela($comp, msg: 'Lançamentos salvos e competência fechada.');
        }

        return $this->tela($comp, msg: 'Lançamentos salvos.');
    }
}
