<?php

namespace App\Services;

use App\Models\Funcionario;
use App\Models\Lancamento;
use App\Support\Br;

/**
 * Lançamentos mensais preenchidos pelo RH (horas extras, faltas, comissão, VR).
 * Cada coluna está ligada a uma rubrica da Domínio para gerar o TXT de importação.
 */
class Lancamentos
{
    /** campo => [rótulo, tipo, rubrica Domínio, campo do cadastro usado como padrão] */
    public const COLUNAS = [
        'horas_extras' => ['Horas extras 50%', 'horas', '202', null],
        'faltas' => ['Dias de falta', 'datas', '8792', null],
        'faltas_vr' => ['Dias de falta VR', 'datas', '', null],
        'comissao' => ['Comissão (R$)', 'moeda', '37', null],
        'desconto_vr' => ['Desconto VR (R$)', 'moeda', '201', 'desconto_vr'],
        'observacao' => ['Observação', 'texto', '', null],
    ];

    /** @return array{0: string, 1: string|null} [valor normalizado, erro] */
    public function normalizar(string $campo, ?string $valor, string $comp = ''): array
    {
        $v = trim((string) $valor);
        if ($v === '') {
            return ['', null];
        }

        return match (self::COLUNAS[$campo][1]) {
            'horas' => $this->horas($v),
            'datas' => $this->datas($v, $comp),
            'moeda' => $this->moeda($v),
            default => [mb_substr($v, 0, 200), null],
        };
    }

    private function horas(string $v): array
    {
        if (preg_match('/^(\d{1,3})\s*[:hH]\s*(\d{1,2})$/', $v, $m) && (int) $m[2] < 60) {
            return [sprintf('%d:%02d', $m[1], $m[2]), null];
        }
        if (preg_match('/^\d{1,3}(?:[,.]\d+)?$/', $v)) {
            $min = (int) round((float) str_replace(',', '.', $v) * 60);

            return [sprintf('%d:%02d', intdiv($min, 60), $min % 60), null];
        }

        return [$v, 'Use horas:minutos, ex.: 18:14'];
    }

    private function datas(string $v, string $comp): array
    {
        [$mes, $ano] = preg_match('/^(\d{2})\/(\d{4})$/', $comp, $c) ? [(int) $c[1], (int) $c[2]] : [0, 0];
        $dias = [];
        foreach (preg_split('/[,;\s]+/', $v, -1, PREG_SPLIT_NO_EMPTY) as $parte) {
            if (! preg_match('/^(\d{1,2})(?:\/(\d{1,2})(?:\/(\d{2,4}))?)?$/', $parte, $m)) {
                return [$v, 'Informe os dias, ex.: 5, 12 (ou 05/11)'];
            }
            $d = (int) $m[1];
            if (! empty($m[2]) && $mes && (int) $m[2] !== $mes) {
                return [$v, "O dia {$parte} não é da competência {$comp}"];
            }
            if ($mes && ($d < 1 || $d > (int) date('t', mktime(0, 0, 0, $mes, 1, $ano)))) {
                return [$v, "Dia {$d} não existe em {$comp}"];
            }
            $dias[$d] = true;
        }
        ksort($dias);

        return [implode(', ', array_map(fn ($d) => $mes ? sprintf('%02d/%02d', $d, $mes) : sprintf('%02d', $d), array_keys($dias))), null];
    }

    private function moeda(string $v): array
    {
        $limpo = str_replace(['R$', ' '], '', $v);
        if (! preg_match('/^-?[\d.]*,?\d+$|^-?\d+(\.\d+)?$/', $limpo)) {
            return [$v, 'Valor inválido, ex.: 782,35'];
        }
        $n = Br::valor($limpo);
        if ($n < 0) {
            return [$v, 'Valor não pode ser negativo'];
        }

        return [Br::moeda($n), null];
    }

    public static function contarDatas(?string $v): int
    {
        return count(preg_split('/[,;]+/', (string) $v, -1, PREG_SPLIT_NO_EMPTY));
    }

    /** Uma linha por funcionário ativo (+ quem já tem lançamento na competência). */
    public function carregar(string $comp): array
    {
        $salvos = Lancamento::where('competencia', $comp)->get()->keyBy('funcionario_codigo');
        $ativos = Funcionario::ativos()->orderBy('nome')->get();
        $extras = Funcionario::whereIn('codigo', $salvos->keys()->diff($ativos->pluck('codigo')))->get();

        return $ativos->concat($extras)->map(function (Funcionario $f) use ($salvos) {
            $s = $salvos->get($f->codigo);
            $valores = $s?->dados ?? [];
            if (! $s) {
                foreach (self::COLUNAS as $campo => [, , , $padrao]) {
                    if ($padrao && $f->{$padrao}) {
                        $valores[$campo] = Br::moeda($f->{$padrao});
                    }
                }
            }

            return ['func' => $f, 'valores' => $valores,
                'atualizado' => $s ? $s->updated_at->format('d/m/Y H:i').' por '.$s->atualizado_por : ''];
        })->all();
    }

    /** Grava os lançamentos. Retorna erros [codigo => [campo => msg]]; linhas com erro não são gravadas. */
    public function salvar(string $comp, array $porCodigo, string $usuario): array
    {
        $erros = [];
        foreach ($porCodigo as $codigo => $valores) {
            $limpos = [];
            $erroLinha = [];
            foreach (array_keys(self::COLUNAS) as $campo) {
                [$v, $err] = $this->normalizar($campo, $valores[$campo] ?? '', $comp);
                if ($err) {
                    $erroLinha[$campo] = $err;
                }
                if ($v !== '') {
                    $limpos[$campo] = $v;
                }
            }
            if ($erroLinha) {
                $erros[$codigo] = $erroLinha;

                continue;
            }
            Lancamento::updateOrCreate(['competencia' => $comp, 'funcionario_codigo' => (string) $codigo],
                ['dados' => $limpos, 'atualizado_por' => $usuario]);
        }

        return $erros;
    }

    public function totais(array $linhas): array
    {
        $min = 0;
        $t = ['faltas' => 0, 'faltas_vr' => 0, 'comissao' => 0.0, 'desconto_vr' => 0.0];
        foreach ($linhas as $l) {
            $v = $l['valores'];
            if (preg_match('/^(\d+):(\d{2})$/', $v['horas_extras'] ?? '', $m)) {
                $min += (int) $m[1] * 60 + (int) $m[2];
            }
            $t['faltas'] += self::contarDatas($v['faltas'] ?? '');
            $t['faltas_vr'] += self::contarDatas($v['faltas_vr'] ?? '');
            foreach (['comissao', 'desconto_vr'] as $c) {
                $t[$c] += Br::valor($v[$c] ?? '');
            }
        }

        return $t + ['horas_extras' => sprintf('%d:%02d', intdiv($min, 60), $min % 60)];
    }
}
