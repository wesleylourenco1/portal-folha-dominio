<?php

namespace App\Services;

use App\Models\Configuracao;
use App\Models\Lancamento;
use App\Support\Br;
use RuntimeException;

/**
 * Gera o arquivo texto de importação de lançamentos da Domínio Folha
 * (leiaute "Importação Arquivo Texto | De Lançamentos" — veja docs/dominio-integracoes.md).
 *
 * Registro 10 (48 posições): "10" + empregado(10) + competência AAAAMM(6) + rubrica(9) + tipo processo(2) + valor(9) + empresa(10)
 * Registro 11 (11 posições):  "11" + data AAAAMMDD(8) + tipo(1: 1 normal, 2 DSR) — um por dia de falta, logo após o 10 da falta.
 */
class DominioTxtLancamentos
{
    /** Configurações com padrão. Os valores marcados "a confirmar" devem ser testados na instalação. */
    public const CONFIG = [
        'dominio_empresa_codigo' => '1',
        'dominio_tipo_processo' => '11',          // 11 = Mensal (confirmado na Domínio 10.6A)
        'dominio_formato_horas' => 'hhmm',        // hhmm = opção 'Horas minutos' da importação | centesimal = 'Horas decimais'
        'dominio_rubrica_horas_extras' => '202',
        'dominio_rubrica_faltas' => '8792',
        'dominio_rubrica_faltas_vr' => '',
        'dominio_rubrica_comissao' => '37',
        'dominio_rubrica_desconto_vr' => '201',
    ];

    public static function cfg(string $chave): string
    {
        return (string) Configuracao::ler($chave, self::CONFIG[$chave]);
    }

    /** @return array{conteudo: string, linhas: int, registros: array<int, array>, avisos: array<int, string>} */
    public function gerar(string $competencia): array
    {
        if (! preg_match('/^(\d{2})\/(\d{4})$/', $competencia, $m)) {
            throw new RuntimeException('Competência inválida.');
        }
        $aaaamm = $m[2].$m[1];
        $empresa = self::cfg('dominio_empresa_codigo');
        $processo = self::cfg('dominio_tipo_processo');
        $linhas = [];
        $registros = [];
        $avisos = [];

        $lancamentos = Lancamento::where('competencia', $competencia)->orderByRaw('CAST(funcionario_codigo AS INTEGER)')->get();
        foreach ($lancamentos as $l) {
            $cod = $l->funcionario_codigo;
            $d = $l->dados;

            if ($v = $d['horas_extras'] ?? null) {
                $this->rubrica($linhas, $registros, $avisos, $cod, $aaaamm, 'horas_extras', $this->horas($v), $processo, $empresa, "{$v} h");
            }
            foreach (['faltas', 'faltas_vr'] as $campo) {
                if ($v = $d[$campo] ?? null) {
                    $dias = array_filter(array_map('trim', explode(',', $v)));
                    if ($this->rubrica($linhas, $registros, $avisos, $cod, $aaaamm, $campo, count($dias) * 100, $processo, $empresa, count($dias).' dia(s)')) {
                        foreach ($dias as $dia) {          // "05/11" -> registro 11 com a data
                            [$dd, $mm] = array_map('intval', explode('/', $dia)) + [1 => (int) $m[1]];
                            $linhas[] = '11'.sprintf('%04d%02d%02d', $m[2], $mm ?: (int) $m[1], $dd).'1';
                        }
                    }
                }
            }
            foreach (['comissao', 'desconto_vr'] as $campo) {
                if (($v = $d[$campo] ?? null) && Br::valor($v) > 0) {
                    $this->rubrica($linhas, $registros, $avisos, $cod, $aaaamm, $campo, (int) round(Br::valor($v) * 100), $processo, $empresa, 'R$ '.$v);
                }
            }
        }

        return [
            // arquivo texto do Windows: ANSI (Windows-1252) com CRLF
            'conteudo' => mb_convert_encoding(implode("\r\n", $linhas).($linhas ? "\r\n" : ''), 'Windows-1252', 'UTF-8'),
            'linhas' => count($linhas),
            'registros' => $registros,
            'avisos' => array_values(array_unique($avisos)),
        ];
    }

    /** Valor em centésimos: "18:14" -> 1814 (hhmm) ou 1823 (centesimal). */
    private function horas(string $v): int
    {
        [$h, $min] = array_map('intval', explode(':', $v) + [1 => 0]);

        return self::cfg('dominio_formato_horas') === 'centesimal'
            ? (int) round(($h + $min / 60) * 100)
            : $h * 100 + $min;
    }

    private function rubrica(array &$linhas, array &$registros, array &$avisos, string $cod, string $aaaamm, string $campo,
        int $valor, string $processo, string $empresa, string $descricao): bool
    {
        $rubrica = self::cfg("dominio_rubrica_{$campo}");
        if ($rubrica === '') {
            $avisos[] = 'Sem rubrica configurada para "'.Lancamentos::COLUNAS[$campo][0].'" — esses lançamentos não entram no arquivo.';

            return false;
        }
        if ($valor > 999999999) {
            $avisos[] = "Valor grande demais para o leiaute (empregado {$cod}, {$campo}).";

            return false;
        }
        $linhas[] = '10'
            .str_pad(Br::digitos($cod), 10, '0', STR_PAD_LEFT)
            .$aaaamm
            .str_pad(Br::digitos($rubrica), 9, '0', STR_PAD_LEFT)
            .str_pad(Br::digitos($processo), 2, '0', STR_PAD_LEFT)
            .str_pad((string) $valor, 9, '0', STR_PAD_LEFT)
            .str_pad(Br::digitos($empresa), 10, '0', STR_PAD_LEFT);
        $registros[] = ['empregado' => $cod, 'rubrica' => $rubrica, 'campo' => Lancamentos::COLUNAS[$campo][0], 'descricao' => $descricao];

        return true;
    }
}
