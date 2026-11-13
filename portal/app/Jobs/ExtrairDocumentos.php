<?php

namespace App\Jobs;

use App\Models\Admissao;
use App\Services\ExtratorDocumentos;
use App\Support\CamposAdmissao;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;

/** Lê os documentos de uma admissão com IA e preenche o formulário para revisão do RH. */
class ExtrairDocumentos implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;
    public int $timeout = 600;

    public function __construct(public int $admissaoId) {}

    public function handle(ExtratorDocumentos $extrator): void
    {
        $adm = Admissao::with('documentos')->findOrFail($this->admissaoId);
        $arquivos = $adm->documentos->map(fn ($d) => [
            'nome' => $d->nome_original,
            'caminho' => Storage::disk('local')->path($d->caminho),
        ])->all();

        try {
            $resultado = $extrator->extrair($arquivos);
        } catch (\Throwable $e) {
            $adm->update(['status' => 'erro_extracao', 'mensagem' => $e->getMessage()]);
            $adm->registrar('Erro na extração: '.$e->getMessage());

            return;
        }

        $dados = $adm->dados ?? [];
        foreach (CamposAdmissao::chaves('doc') as $campo) {
            if (! empty($resultado[$campo]) && empty($dados[$campo])) {   // o que o RH digitou tem prioridade
                $dados[$campo] = $resultado[$campo];
            }
        }
        $adm->update(['status' => 'revisar', 'dados' => $dados, 'extracao' => $resultado, 'mensagem' => null]);
        $adm->registrar('Documentos lidos por '.($resultado['_ia'] ?? 'IA').': '
            .implode(', ', $resultado['documentos_identificados'] ?? []));
    }
}
