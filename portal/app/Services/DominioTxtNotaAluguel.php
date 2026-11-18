<?php

namespace App\Services;

use App\Models\Aluguel;
use App\Models\Configuracao;

/**
 * Arquivo da Importação Padrão da Escrita Fiscal — "Leiaute Domínio Sistemas com Separador" (manual
 * C:\Contabil\Importação Padrão.pdf, cap. 16): campos separados por "|", decimais com vírgula, datas dd/mm/aaaa.
 *
 *   0000 - empresa (CNPJ)
 *   1000 - nota fiscal de entrada (espécie, fornecedor pelo CPF/CNPJ, acumulador, CFOP, documento, datas, valores)
 *   1020 - imposto da nota (63 = IRRF-APF: base ajustada, alíquota, valor, código de recolhimento)
 */
class DominioTxtNotaAluguel
{
    public const CONFIG = [
        'aluguel_especie' => '42',                 // Documento Não Fiscal
        'aluguel_fornecedor_inscricao' => '',      // CPF/CNPJ do locador (só números)
        'aluguel_codigo_imposto' => '63',          // IRRF-APF
    ];

    public static function config(string $c): string
    {
        return (string) (Configuracao::ler($c) ?: (self::CONFIG[$c] ?? ''));
    }

    private static function dec(?float $v): string
    {
        return $v === null ? '' : number_format($v, 2, ',', '');
    }

    /** Linha com os campos nas posições do leiaute (posição 1 = identificação do registro). */
    private static function linha(array $campos): string
    {
        $n = max(array_keys($campos));
        $l = [];
        for ($i = 1; $i <= $n; $i++) {
            $l[] = str_replace(['|', "\r", "\n"], ' ', (string) ($campos[$i] ?? ''));
        }

        return '|'.implode('|', $l).'|';
    }

    public static function gerar(Aluguel $a, string $cnpjEmpresa): array
    {
        $c = $a->calculo ?? $a->calcular();
        $inscricao = preg_replace('/\D/', '', self::config('aluguel_fornecedor_inscricao'));
        if (! $inscricao) {
            throw new \RuntimeException('Informe o CPF/CNPJ do locador (fornecedor) em Configurações.');
        }
        $codRec = Aluguel::config('aluguel_cod_recolhimento');
        $linhas = [
            self::linha([1 => '0000', 2 => preg_replace('/\D/', '', $cnpjEmpresa)]),
            self::linha([
                1 => '1000', 2 => self::config('aluguel_especie'), 3 => $inscricao,
                5 => Aluguel::config('aluguel_acumulador'), 6 => Aluguel::config('aluguel_cfop'),
                8 => $a->documento(), 11 => $a->data_nota, 12 => $a->data_nota,
                13 => self::dec($c['valor_contabil']), 17 => 'T', 21 => $codRec,
                27 => self::dec($c['seguro']), 39 => self::dec($c['valor_produtos']),
            ]),
            self::linha([
                1 => '1020', 2 => self::config('aluguel_codigo_imposto'), 3 => '0,00',
                4 => self::dec($c['base']), 5 => '27,50', 6 => self::dec($c['irrf']),
                7 => '0,00', 8 => '0,00', 9 => '0,00', 10 => '0,00',
                11 => self::dec($c['valor_contabil']), 12 => $codRec,
            ]),
        ];

        return [
            'arquivo' => 'aluguel_'.str_replace('/', '', $a->competencia).'.txt',
            'conteudo' => mb_convert_encoding(implode("\r\n", $linhas)."\r\n", 'Windows-1252', 'UTF-8'),
            'linhas' => $linhas,
        ];
    }
}
