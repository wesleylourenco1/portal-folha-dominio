<?php

namespace App\Http\Controllers;

use App\Models\Configuracao;
use App\Models\Folha;
use App\Models\TarefaAgente;
use App\Services\FolhaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class FolhaController extends Controller
{
    private function contexto(array $extra = []): array
    {
        $cfg = [];
        foreach (['adiantamento_automatico' => 'nao', 'adiantamento_dia_gerar' => '15', 'adiantamento_dia_vencimento' => '20',
            'adiantamento_status' => ''] as $c => $p) {
            $cfg[$c] = Configuracao::ler($c, $p);
        }

        return $extra + [
            'folhas' => Folha::all()->sortByDesc(fn ($f) => $f->chaveOrdem())->values(),
            'cfg' => $cfg,
            'pendente' => TarefaAgente::pendente('gerar_adiantamento'),
            'compAtual' => now()->format('m/Y'),
        ];
    }

    public function index()
    {
        return view('folha.index', $this->contexto());
    }

    public function importar(Request $request, FolhaService $folhas)
    {
        $request->validate(['extrato' => 'required|file|mimes:pdf|max:20480']);
        try {
            $folha = $folhas->importar($request->file('extrato')->getRealPath(), $request->user()->name,
                'PDF enviado: '.$request->file('extrato')->getClientOriginalName());
        } catch (\Throwable $e) {
            return back()->withErrors(['extrato' => 'Não foi possível ler o PDF: '.$e->getMessage()]);
        }

        return redirect()->route('folha.show', $folha);
    }

    public function adiantamento(Request $request)
    {
        if ($request->input('acao') === 'gerar') {
            $comp = (string) $request->input('competencia');
            $request->validate(['competencia' => ['required', 'regex:/^\d{2}\/\d{4}$/']]);
            if (! TarefaAgente::pendente('gerar_adiantamento')) {
                TarefaAgente::enfileirar('gerar_adiantamento', ['competencia' => $comp], $request->user()->name);
            }
            Configuracao::salvar(['adiantamento_status' => "Solicitado por {$request->user()->name} em ".now()->format('d/m/Y H:i').' - aguardando o robô']);
        } else {
            Configuracao::salvar([
                'adiantamento_automatico' => $request->boolean('adiantamento_automatico') ? 'sim' : 'nao',
                'adiantamento_dia_gerar' => (string) max(1, min(28, (int) $request->input('adiantamento_dia_gerar', 15))),
                'adiantamento_dia_vencimento' => (string) max(1, min(31, (int) $request->input('adiantamento_dia_vencimento', 20))),
            ]);
        }

        return redirect()->route('folha.index');
    }

    public function show(Folha $folha)
    {
        $ca = app(\App\Services\ContaAzul::class);

        return view('folha.show', ['f' => $folha, 'd' => $folha->dados,
            'caConectado' => $ca->conectado(), 'caPendencias' => $ca->conectado() ? $ca->pendencias() : []]);
    }

    public function pdf(Request $request, Folha $folha, string $tipo)
    {
        abort_unless(in_array($tipo, ['extrato', 'liquidos', 'liquidos_dominio'], true), 404);
        $caminho = $folha->pasta()."/{$tipo}.pdf";
        if ($tipo === 'liquidos' && ! Storage::disk('local')->exists($caminho)) {
            app(FolhaService::class)->gerarRelacaoLiquidos($folha);
        }
        abort_unless(Storage::disk('local')->exists($caminho), 404, 'PDF não disponível para esta folha.');
        $nome = str_replace(['/', ' '], ['-', '_'], "{$tipo}_{$folha->calculo}_{$folha->competencia}.pdf");
        $disposicao = $request->boolean('baixar') ? 'attachment' : 'inline';

        return Storage::disk('local')->response($caminho, $nome, ['Content-Type' => 'application/pdf'], $disposicao);
    }
}
