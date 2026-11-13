<?php

namespace App\Support;

/** Utilitários brasileiros: CPF, PIS, moeda e datas. */
class Br
{
    public static function digitos(?string $v): string
    {
        return preg_replace('/\D/', '', (string) $v);
    }

    public static function cpfValido(?string $cpf): bool
    {
        $c = self::digitos($cpf);
        if (strlen($c) !== 11 || preg_match('/^(\d)\1{10}$/', $c)) {
            return false;
        }
        foreach ([9, 10] as $n) {
            $soma = 0;
            for ($i = 0; $i < $n; $i++) {
                $soma += (int) $c[$i] * ($n + 1 - $i);
            }
            if ((($soma * 10) % 11) % 10 !== (int) $c[$n]) {
                return false;
            }
        }

        return true;
    }

    public static function pisValido(?string $pis): bool
    {
        $p = self::digitos($pis);
        if (strlen($p) !== 11 || preg_match('/^(\d)\1{10}$/', $p)) {
            return false;
        }
        $pesos = [3, 2, 9, 8, 7, 6, 5, 4, 3, 2];
        $soma = 0;
        foreach ($pesos as $i => $peso) {
            $soma += (int) $p[$i] * $peso;
        }
        $resto = $soma % 11;

        return ($resto < 2 ? 0 : 11 - $resto) === (int) $p[10];
    }

    public static function dataValida(?string $d): bool
    {
        if (! preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', (string) $d, $m)) {
            return false;
        }

        return checkdate((int) $m[2], (int) $m[1], (int) $m[3]);
    }

    /** "1.234,56" -> 1234.56 */
    public static function valor(?string $v): float
    {
        $v = trim(str_replace(['R$', ' '], '', (string) $v));
        if ($v === '') {
            return 0.0;
        }
        if (str_contains($v, ',')) {
            $v = str_replace(['.', ','], ['', '.'], $v);
        }

        return (float) $v;
    }

    /** 1234.56 -> "1.234,56" */
    public static function moeda(float|int|null $v): string
    {
        return number_format((float) $v, 2, ',', '.');
    }

    /** Valida os campos obrigatórios e documentos de uma admissão. Retorna [campo => mensagem]. */
    public static function validarAdmissao(array $d): array
    {
        $erros = [];
        if (! empty($d['cpf']) && ! self::cpfValido($d['cpf'])) {
            $erros['cpf'] = 'CPF com dígito verificador inválido';
        }
        if (! empty($d['pis']) && ! self::pisValido($d['pis'])) {
            $erros['pis'] = 'PIS/NIS com dígito verificador inválido';
        }
        foreach (['data_nascimento', 'data_admissao', 'rg_data_emissao', 'ctps_data_emissao'] as $campo) {
            if (! empty($d[$campo]) && ! self::dataValida($d[$campo])) {
                $erros[$campo] = 'Use o formato DD/MM/AAAA';
            }
        }
        foreach (['nome', 'cpf', 'data_nascimento', 'data_admissao', 'cargo', 'salario'] as $obrigatorio) {
            if (empty($d[$obrigatorio])) {
                $erros[$obrigatorio] ??= 'Campo obrigatório';
            }
        }

        return $erros;
    }
}
