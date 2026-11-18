<?php

namespace App\Services;

use App\Models\Aluguel;
use RuntimeException;

/**
 * Lê o print/PDF do e-mail da imobiliária com a IA configurada (Claude ou OpenAI) e devolve
 * as linhas de entrada, o IRRF descontado, o saldo do boleto e o vencimento.
 */
class LeitorAluguel
{
    public function __construct(private ExtratorDocumentos $extrator) {}

    public static function instrucoes(): string
    {
        return <<<'TXT'
        Você recebe o print (ou PDF) de um e-mail/demonstrativo de uma imobiliária brasileira cobrando o aluguel
        de um imóvel alugado por uma empresa (locatária). Extraia:

        - competencia: mês/ano do aluguel cobrado, MM/AAAA (ex.: "Aluguel OUTUBRO" de 2026 -> "10/2026").
          Se só houver o vencimento, a competência é o mês anterior ao vencimento.
        - itens: TODAS as linhas de valores cobrados da locatária (entradas/créditos do boleto), uma por linha, na ordem:
            tipo "aluguel" para aluguel (inclusive proporcional, com ou sem reajuste/IGPM);
            tipo "seguro" para seguro fiança, seguro incêndio ou garantia locatícia;
            tipo "iptu" para IPTU (cada parcela/inscrição em uma linha);
            tipo "outro" para condomínio, taxas, multas, juros, despesas bancárias e qualquer outra cobrança.
          descricao: o texto da linha como está no documento. valor: número positivo em reais (ex.: 4160.34).
          NÃO inclua nos itens o IRRF, descontos, abatimentos, totais, subtotais nem o saldo.
        - irrf: valor do IRRF (imposto de renda retido na fonte) descontado/abatido no demonstrativo, número; 0 se não houver.
        - valor_boleto: saldo/valor final a pagar no boleto, número; 0 se não aparecer.
        - vencimento: data de vencimento do boleto DD/MM/AAAA; "" se não aparecer.
        - imobiliaria: nome da imobiliária; "" se não aparecer.
        - campos_incertos: nomes dos campos cuja leitura ficou duvidosa (imagem cortada, borrada).
        - observacoes: algo fora do comum (ex.: linha que você não soube classificar). "" se nada.

        Nunca invente valores: copie exatamente o que está no documento.
        TXT;
    }

    public static function schema(): array
    {
        $item = [
            'type' => 'object',
            'properties' => [
                'tipo' => ['type' => 'string', 'enum' => array_keys(Aluguel::TIPOS)],
                'descricao' => ['type' => 'string'],
                'valor' => ['type' => 'number'],
            ],
            'required' => ['tipo', 'descricao', 'valor'],
            'additionalProperties' => false,
        ];
        $props = [
            'competencia' => ['type' => 'string'],
            'itens' => ['type' => 'array', 'items' => $item],
            'irrf' => ['type' => 'number'],
            'valor_boleto' => ['type' => 'number'],
            'vencimento' => ['type' => 'string'],
            'imobiliaria' => ['type' => 'string'],
            'campos_incertos' => ['type' => 'array', 'items' => ['type' => 'string']],
            'observacoes' => ['type' => 'string'],
        ];

        return ['type' => 'object', 'properties' => $props, 'required' => array_keys($props), 'additionalProperties' => false];
    }

    /** @param  array<int, array{nome: string, caminho: string}>  $arquivos */
    public function ler(array $arquivos): array
    {
        $r = $this->extrator->extrairCom($arquivos, self::instrucoes(), self::schema(), 'correspondencia_aluguel');

        $itens = [];
        foreach ((array) ($r['itens'] ?? []) as $i) {
            $valor = round(abs((float) ($i['valor'] ?? 0)), 2);
            if ($valor > 0 && isset(Aluguel::TIPOS[$i['tipo'] ?? ''])) {
                $itens[] = ['tipo' => $i['tipo'], 'descricao' => trim((string) ($i['descricao'] ?? '')), 'valor' => $valor];
            }
        }
        if (! $itens) {
            throw new RuntimeException('A IA não encontrou as linhas do aluguel no arquivo. Confira se é o e-mail da imobiliária.');
        }
        $comp = preg_match('/^(\d{1,2})\/(\d{4})$/', trim((string) ($r['competencia'] ?? '')), $m)
            ? sprintf('%02d/%s', $m[1], $m[2]) : null;
        $venc = preg_match('/^\d{2}\/\d{2}\/\d{4}$/', trim((string) ($r['vencimento'] ?? ''))) ? trim($r['vencimento']) : null;

        return [
            'competencia' => $comp,
            'itens' => $itens,
            'irrf' => round(abs((float) ($r['irrf'] ?? 0)), 2),
            'valor_boleto' => ($b = round(abs((float) ($r['valor_boleto'] ?? 0)), 2)) > 0 ? $b : null,
            'vencimento' => $venc,
            'imobiliaria' => trim((string) ($r['imobiliaria'] ?? '')),
            'campos_incertos' => array_values(array_filter((array) ($r['campos_incertos'] ?? []))),
            'observacoes' => trim((string) ($r['observacoes'] ?? '')),
            '_ia' => $r['_ia'] ?? '',
        ];
    }
}
