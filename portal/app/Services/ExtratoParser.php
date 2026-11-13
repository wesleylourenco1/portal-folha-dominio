<?php

namespace App\Services;

use RuntimeException;
use Smalot\PdfParser\Parser;

/**
 * Lê o relatório "Extrato Mensal" da Domínio Folha (PDF) e devolve os dados estruturados:
 * funcionários, eventos (rubricas), consignados e totais.
 *
 * O texto do PDF é remontado linha a linha pelas coordenadas de cada trecho, porque a
 * ordem "natural" do arquivo vem embaralhada.
 */
class ExtratoParser
{
    private const NUM = '-?[\d.]+,\d{2}';
    private const CONSIGNADO = ['CRED. TRAB', 'CONSIG', 'EMPRESTIMO', 'EMPRÉSTIMO', 'EMP. CRED'];

    public function lerArquivo(string $caminho): array
    {
        return $this->lerTexto($this->textoPdf($caminho));
    }

    public function textoPdf(string $caminho): string
    {
        $pdf = (new Parser)->parseFile($caminho);
        $paginas = [];
        foreach ($pdf->getPages() as $pagina) {
            $linhas = [];
            foreach ($pagina->getDataTm() as [$tm, $texto]) {
                $y = (float) $tm[5];
                $chave = null;
                foreach (array_keys($linhas) as $k) {
                    if (abs($k - $y) <= 2) {
                        $chave = $k;
                        break;
                    }
                }
                $linhas[(string) ($chave ?? $y)][] = [(float) $tm[4], $texto];
            }
            uksort($linhas, fn ($a, $b) => (float) $b <=> (float) $a);   // de cima para baixo
            foreach ($linhas as $partes) {
                usort($partes, fn ($a, $b) => $a[0] <=> $b[0]);         // da esquerda para a direita
                $paginas[] = trim(preg_replace('/[ \t]+/', ' ', implode(' ', array_map(fn ($p) => trim($p[1]), $partes))));
            }
        }

        return implode("\n", $paginas);
    }

    public function lerTexto(string $txt): array
    {
        $txt = str_replace("\r", '', $txt);
        if (! str_contains($txt, 'EXTRATO MENSAL')) {
            throw new RuntimeException('Este PDF não é um Extrato Mensal da Domínio Folha.');
        }
        $n = self::NUM;
        preg_match('/Compet[êe]ncia:\s*(\d{2}\/\d{4})/u', $txt, $comp);
        preg_match('/Empresa:\s*(\d+)\s*-\s*(.+?)(?:\s+P[áa]gina:|\n)/u', $txt, $emp);
        preg_match('/(\d{2}\.\d{3}\.\d{3}\/\d{4}-\d{2})/', $txt, $cnpj);
        preg_match('/C[áa]lculo:\s*(.+?)(?:\s+Horas:|\n)/u', $txt, $calc);

        $funcionarios = [];
        $blocos = preg_split('/(?=Empr\.: \d+ )/u', $txt);
        array_shift($blocos);
        foreach ($blocos as $b) {
            if (! preg_match('/Empr\.: (\d+) (.+?) Situa[çc][ãa]o: (.+?) CPF: ([\d.\-]+) Adm: (\d{2}\/\d{2}\/\d{4})/u', $b, $cab)) {
                continue;
            }
            if (! preg_match("/Proventos: ($n) Descontos: ($n).*?L[íi]quido: ($n)/su", $b, $tot)) {
                continue;
            }
            preg_match("/Cargo: (\d+) (.+?) C\.B\.O: (\d+).*?Sal[áa]rio: ($n)/u", $b, $cargo);
            preg_match("/Valor FGTS: ($n)/u", $b, $fgts);
            $corpo = str_contains($b, 'ND:') ? substr($b, 0, strpos($b, 'ND:')) : $b;
            $rubricas = $this->rubricas($corpo);
            $funcionarios[] = [
                'codigo' => $cab[1], 'nome' => trim($cab[2]), 'situacao' => trim($cab[3]),
                'cpf' => $cab[4], 'admissao' => $cab[5],
                'cargo_codigo' => $cargo[1] ?? '', 'cargo' => trim($cargo[2] ?? ''),
                'cbo' => $cargo[3] ?? '', 'salario' => isset($cargo[4]) ? self::valor($cargo[4]) : 0.0,
                'proventos' => self::valor($tot[1]), 'descontos' => self::valor($tot[2]), 'liquido' => self::valor($tot[3]),
                'fgts' => isset($fgts[1]) ? self::valor($fgts[1]) : 0.0,
                'rubricas' => $rubricas,
                'consignado' => round(array_sum(array_map(fn ($r) => $r['consignado'] ? $r['valor'] : 0, $rubricas)), 2),
            ];
        }
        if (! $funcionarios) {
            throw new RuntimeException('Não encontrei funcionários no extrato.');
        }

        preg_match("/Total Geral Proventos: ($n) Total Geral Descontos: ($n)/u", $txt, $geral);
        preg_match("/L[íi]quido Geral: ($n)/u", $txt, $liqGeral);
        preg_match("/Total INSS: ($n)/u", $txt, $inss);
        preg_match("/Valor do FGTS: ($n)/u", $txt, $fgtsTotal);
        preg_match("/IRRF\(Folha\): ($n)/u", $txt, $irrf);

        $soma = round(array_sum(array_column($funcionarios, 'liquido')), 2);
        $totalLiquido = isset($liqGeral[1]) ? self::valor($liqGeral[1]) : $soma;

        return [
            'competencia' => $comp[1] ?? '',
            'empresa_codigo' => $emp[1] ?? '',
            'empresa_nome' => trim($emp[2] ?? ''),
            'cnpj' => $cnpj[1] ?? '',
            'calculo' => trim($calc[1] ?? ''),
            'funcionarios' => $funcionarios,
            'total_proventos' => isset($geral[1]) ? self::valor($geral[1]) : round(array_sum(array_column($funcionarios, 'proventos')), 2),
            'total_descontos' => isset($geral[2]) ? self::valor($geral[2]) : round(array_sum(array_column($funcionarios, 'descontos')), 2),
            'total_liquido' => $totalLiquido,
            'total_inss' => isset($inss[1]) ? self::valor($inss[1]) : 0.0,
            'total_fgts' => isset($fgtsTotal[1]) ? self::valor($fgtsTotal[1]) : 0.0,
            'total_irrf' => isset($irrf[1]) ? self::valor($irrf[1]) : 0.0,
            'total_consignado' => round(array_sum(array_column($funcionarios, 'consignado')), 2),
            'confere' => abs($soma - $totalLiquido) < 0.01,
        ];
    }

    /** Eventos (proventos/descontos) de um funcionário. Cada linha do extrato tem até 2 eventos. */
    private function rubricas(string $bloco): array
    {
        $n = self::NUM;
        $saida = [];
        foreach (explode("\n", $bloco) as $linha) {
            if (preg_match('/^(Empr\.|V[íi]nculo|Cargo|ND:|NF:)/u', $linha) || str_contains($linha, 'Proventos:')) {
                continue;
            }
            preg_match_all("/(\d+) (.+?) ([\d.:,]+) ($n) ([PD])(?=\s|$)/u", $linha, $ms, PREG_SET_ORDER);
            foreach ($ms as $m) {
                $desc = trim($m[2]);
                $maiusc = mb_strtoupper($desc);
                $saida[] = [
                    'codigo' => $m[1], 'descricao' => $desc, 'referencia' => $m[3], 'valor' => self::valor($m[4]),
                    'tipo' => $m[5] === 'P' ? 'provento' : 'desconto',
                    'consignado' => $m[5] === 'D' && ! str_contains($maiusc, 'ESTORNO')
                        && collect(self::CONSIGNADO)->contains(fn ($p) => str_contains($maiusc, $p)),
                ];
            }
        }

        return $saida;
    }

    private static function valor(string $v): float
    {
        return (float) str_replace(['.', ','], ['', '.'], $v);
    }
}
