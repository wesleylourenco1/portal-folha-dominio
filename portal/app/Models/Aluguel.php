<?php

namespace App\Models;

use App\Services\IrrfAluguel;
use Illuminate\Database\Eloquent\Model;

/** Aluguel mensal (correspondência da imobiliária) e a nota de entrada correspondente na Escrita Fiscal. */
class Aluguel extends Model
{
    protected $table = 'alugueis';

    protected $fillable = ['competencia', 'data_nota', 'vencimento', 'itens', 'irrf_imobiliaria', 'valor_boleto', 'perc_administracao',
        'calculo', 'status', 'mensagem', 'observacoes', 'atualizado_por'];

    protected $casts = ['itens' => 'array', 'calculo' => 'array', 'irrf_imobiliaria' => 'float', 'valor_boleto' => 'float', 'perc_administracao' => 'float'];

    public const TIPOS = [
        'aluguel' => 'Aluguel (entra na base do IRRF)',
        'seguro' => 'Seguro (fiança/incêndio)',
        'iptu' => 'IPTU (fora da nota)',
        'outro' => 'Outro (fora da nota)',
    ];

    public const STATUS = ['rascunho' => 'Rascunho', 'na_fila' => 'Na fila do robô', 'lancado' => 'Lançado na Domínio', 'erro' => 'Erro no lançamento'];

    /** Configuração da nota na Escrita Fiscal (pode mudar em Configurações). */
    public const NOTA = [
        'aluguel_fornecedor' => '2', 'aluguel_acumulador' => '602', 'aluguel_cfop' => '1949',
        'aluguel_cod_recolhimento' => '320806', 'aluguel_imposto' => 'IRRF-APF',
    ];

    public static function config(string $chave): string
    {
        return (string) Configuracao::ler($chave, self::NOTA[$chave] ?? '');
    }

    /** Número do documento como nas notas já lançadas: mês + ano (09/2026 -> 92026). */
    public function documento(): string
    {
        [$m, $a] = explode('/', $this->competencia);

        return ((int) $m).$a;
    }

    public function total(string $tipo): float
    {
        return round(array_sum(array_map(fn ($i) => $i['tipo'] === $tipo ? (float) $i['valor'] : 0, $this->itens ?? [])), 2);
    }

    /** Valores da nota: base ajustada para o IRRF bater com o da imobiliária. */
    public function calcular(): array
    {
        $alugueis = array_values(array_map(fn ($i) => (float) $i['valor'], array_filter($this->itens ?? [], fn ($i) => $i['tipo'] === 'aluguel')));
        $natural = IrrfAluguel::calcular($alugueis, $this->competencia, $this->perc_administracao);
        $produtos = $this->total('aluguel');
        $seguro = $this->total('seguro');
        $entradas = round(array_sum(array_map(fn ($i) => (float) $i['valor'], $this->itens ?? [])), 2);
        $irrf = $this->irrf_imobiliaria ?: $natural['irrf'];
        $base = $this->irrf_imobiliaria ? IrrfAluguel::baseParaIrrf($irrf, $this->competencia, $natural['base']) : $natural['base'];

        return [
            'valor_produtos' => $produtos, 'seguro' => $seguro, 'valor_contabil' => round($produtos + $seguro, 2),
            'base_natural' => $natural['base'], 'irrf_natural' => $natural['irrf'], 'reducao_natural' => $natural['reducao'],
            'irrf' => $irrf, 'base' => $base, 'ajuste_base' => $base !== null ? round($base - $natural['base'], 2) : null,
            'diferenca_irrf' => round($irrf - $natural['irrf'], 2),
            'irrf_conferido' => $base !== null ? IrrfAluguel::irrfDaBase($base, $this->competencia) : null,
            'entradas' => $entradas, 'boleto_calculado' => round($entradas - $irrf, 2),
            'boleto' => $this->valor_boleto ?: round($entradas - $irrf, 2),
        ];
    }
}
