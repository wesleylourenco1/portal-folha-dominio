<?php

namespace App\Http\Controllers;

use App\Models\Configuracao;
use App\Models\Folha;
use App\Models\TarefaAgente;
use App\Services\ContaAzul;
use App\Services\EsocialStatusFolha;
use App\Services\FolhaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
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
            'agenteVisto' => Cache::get('agente_visto_em'),
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
        $ca = app(ContaAzul::class);

        return view('folha.show', ['f' => $folha, 'd' => $folha->dados,
            'caConectado' => $ca->conectado(), 'caPendencias' => $ca->conectado() ? $ca->pendencias() : []]);
    }

    /**
     * eSocial da folha mensal: "transmitir" manda o robô enviar S-1200, S-1210 e o fechamento S-1299 na Domínio
     * (Relatórios > e-Social > Eventos periódicos; DCTFWeb fica de fora) e conferir; "conferir" só confere o status.
     */
    public function esocial(Request $request, Folha $folha)
    {
        $acao = $request->input('acao') === 'transmitir' ? 'transmitir' : 'conferir';
        $voltar = redirect()->route('folha.show', $folha);
        if ($folha->ehAdiantamento()) {
            return $voltar->withErrors(['esocial' => 'Adiantamento não tem eSocial; transmita a folha mensal.']);
        }
        if (in_array($folha->esocial_status, ['na_fila', 'aguardando', 'fechando'], true)) {
            return $voltar->withErrors(['esocial' => 'O robô já está cuidando do eSocial desta folha; aguarde.']);
        }
        if ($acao === 'transmitir' && $folha->esocial_status === 'transmitido') {
            return $voltar->withErrors(['esocial' => 'O eSocial desta folha já foi transmitido e o fechamento está validado.']);
        }
        // remunerações já validadas: só falta o fechamento
        $etapa = $acao === 'conferir' ? 'conferir' : ($folha->esocial_status === 'validado' ? 'fechamento' : 'remuneracao');
        EsocialStatusFolha::enfileirar($folha, $etapa, $acao, $request->user()->name);
        $folha->update(['esocial_status' => 'na_fila', 'esocial_tentativas' => 0, 'esocial_mensagem' => match ($etapa) {
            'remuneracao' => 'Aguardando o robô enviar S-1200 e S-1210; depois de validados ele envia o fechamento S-1299 (a DCTFWeb continua com você).',
            'fechamento' => 'Aguardando o robô enviar o fechamento S-1299 (a DCTFWeb continua com você).',
            default => 'Aguardando o robô conferir o status na Domínio.',
        }] + ($acao === 'transmitir' ? ['esocial_transmitido_por' => $request->user()->name] : []));

        return $voltar->with('mensagem', $acao === 'transmitir' ? 'Enviado ao robô: ele transmite o eSocial e confere sozinho.' : 'Enviado ao robô: ele confere o status do eSocial.');
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
