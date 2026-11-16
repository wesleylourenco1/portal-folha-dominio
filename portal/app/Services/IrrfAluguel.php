<?php

namespace App\Services;

/**
 * IRRF sobre aluguel pago por PJ a pessoa física, do jeito que a Domínio Escrita Fiscal calcula
 * (conferido com a nota real de 09/2026: base 7.254,58 → IRRF 1.073,57).
 *
 * - Base = aluguel menos a taxa de administração (padrão 10%), arredondada por linha de aluguel.
 *   Seguro, IPTU e outros reembolsos não entram na base.
 * - Tabela progressiva mensal vigente desde 05/2025.
 * - A partir de 01/2026, redução da Lei 15.270/2025: até R$ 5.000 zera (limite R$ 312,89);
 *   de R$ 5.000,01 a R$ 7.350,00 reduz 978,62 − 0,133145 × rendimento; acima disso, nada.
 */
class IrrfAluguel
{
    /** [limite superior, alíquota, parcela a deduzir] */
    public const TABELA = [
        [2428.80, 0.0, 0.0],
        [2826.65, 0.075, 182.16],
        [3751.05, 0.15, 394.16],
        [4664.68, 0.225, 675.49],
        [PHP_FLOAT_MAX, 0.275, 908.73],
    ];

    public static function base(array $alugueis, float $percAdministracao = 10.0): float
    {
        $fator = 1 - $percAdministracao / 100;

        return round(array_sum(array_map(fn ($v) => round((float) $v * $fator + 1e-9, 2), $alugueis)), 2);
    }

    public static function impostoTabela(float $base): float
    {
        foreach (self::TABELA as [$limite, $aliquota, $deduzir]) {
            if ($base <= $limite) {
                return max(0.0, $base * $aliquota - $deduzir);
            }
        }

        return 0.0;
    }

    /** Redução de 2026 (Lei 15.270/2025) sobre o imposto da tabela. */
    public static function reducao(float $base, float $imposto, string $competencia): float
    {
        [, $ano] = array_map('intval', explode('/', $competencia));
        if ($ano < 2026) {
            return 0.0;
        }
        if ($base <= 5000.00) {
            return min(312.89, $imposto);
        }
        if ($base <= 7350.00) {
            return max(0.0, min($imposto, 978.62 - 0.133145 * $base));
        }

        return 0.0;
    }

    /** IRRF que a Domínio calcula para uma base digitada (tabela + redução, arredondado). */
    public static function irrfDaBase(float $base, string $competencia): float
    {
        $t = self::impostoTabela($base);

        return round($t - self::reducao($base, $t, $competencia) + 1e-9, 2);
    }

    /**
     * Base de cálculo a digitar na nota para a Domínio chegar exatamente no IRRF informado pela imobiliária.
     * Busca binária em centavos (o IRRF cresce com a base). Retorna null se nenhuma base dá o valor exato.
     */
    public static function baseParaIrrf(float $irrf, string $competencia, ?float $perto = null): ?float
    {
        if ($irrf <= 0) {
            return null;
        }
        [$lo, $hi] = [0, 100_000_000];           // centavos (até R$ 1 milhão)
        while ($lo < $hi) {
            $meio = intdiv($lo + $hi, 2);
            self::irrfDaBase($meio / 100, $competencia) < $irrf - 0.004 ? $lo = $meio + 1 : $hi = $meio;
        }
        $candidatas = [];
        for ($c = $lo; $c < $lo + 500; $c++) {   // todas as bases que dão o mesmo IRRF
            if (abs(self::irrfDaBase($c / 100, $competencia) - $irrf) < 0.005) {
                $candidatas[] = $c / 100;
            } elseif ($candidatas) {
                break;
            }
        }
        if (! $candidatas) {
            return null;
        }
        if ($perto === null) {
            return $candidatas[0];
        }
        usort($candidatas, fn ($a, $b) => abs($a - $perto) <=> abs($b - $perto));

        return $candidatas[0];                   // a mais próxima da base "natural"
    }

    /** Cálculo completo. $competencia = MM/AAAA do aluguel. */
    public static function calcular(array $alugueis, string $competencia, float $percAdministracao = 10.0): array
    {
        $base = self::base($alugueis, $percAdministracao);
        $tabela = self::impostoTabela($base);
        $reducao = self::reducao($base, $tabela, $competencia);

        return [
            'aluguel' => round(array_sum(array_map('floatval', $alugueis)), 2),
            'base' => $base,
            'imposto_tabela' => round($tabela, 2),
            'reducao' => round($reducao, 2),
            'irrf' => round($tabela - $reducao + 1e-9, 2),
        ];
    }
}
