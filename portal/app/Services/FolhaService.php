<?php

namespace App\Services;

use App\Models\Configuracao;
use App\Models\Folha;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;

/** Importa extratos da Domínio, guarda os PDFs e gera a relação de líquidos. */
class FolhaService
{
    public function __construct(private ExtratoParser $parser, private FuncionarioSync $sync) {}

    /**
     * @param  string  $pdfExtrato  caminho do Extrato Mensal (PDF)
     * @param  string|null  $pdfLiquidos  caminho da Relação de Líquidos da Domínio (opcional)
     */
    public function importar(string $pdfExtrato, string $usuario, string $origem, ?string $pdfLiquidos = null, array $extras = []): Folha
    {
        $extrato = $this->parser->lerArquivo($pdfExtrato) + $extras;

        $folha = Folha::updateOrCreate(
            ['competencia' => $extrato['competencia'], 'empresa_codigo' => $extrato['empresa_codigo'], 'calculo' => $extrato['calculo']],
            ['empresa_nome' => $extrato['empresa_nome'], 'cnpj' => $extrato['cnpj'], 'dados' => $extrato,
                'origem' => $origem, 'importado_por' => $usuario],
        );
        $disco = Storage::disk('local');
        $disco->put($folha->pasta().'/extrato.pdf', file_get_contents($pdfExtrato));
        if ($pdfLiquidos && is_file($pdfLiquidos)) {
            $disco->put($folha->pasta().'/liquidos_dominio.pdf', file_get_contents($pdfLiquidos));
        }
        $this->gerarRelacaoLiquidos($folha);
        $this->sync->atualizarDeExtrato($extrato);

        if ($folha->ehAdiantamento()) {
            Configuracao::salvar(['adiantamento_status' => "OK {$folha->competencia} - importado em ".now()->format('d/m/Y H:i')]);
        }

        return $folha;
    }

    public function gerarRelacaoLiquidos(Folha $folha): string
    {
        $funcionarios = collect($folha->dados['funcionarios'])->sortBy('nome')->values();
        $pdf = Pdf::loadView('pdf.liquidos', ['folha' => $folha, 'd' => $folha->dados, 'funcionarios' => $funcionarios])
            ->setPaper('a4', 'landscape');
        $caminho = $folha->pasta().'/liquidos.pdf';
        Storage::disk('local')->put($caminho, $pdf->output());

        return $caminho;
    }
}
