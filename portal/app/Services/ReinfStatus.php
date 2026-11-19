<?php

namespace App\Services;

use App\Models\Aluguel;
use App\Models\TarefaAgente;
use Smalot\PdfParser\Parser;

/**
 * EFD-Reinf do mês pela Escrita Fiscal (Relatórios > Informativos > Federais > EFD-Reinf):
 * "enviar" = Entrega completa (R-4010 do aluguel + fechamento R-4099); a conferência lê o relatório da
 * "Consulta Envio dos Arquivos" (evento, situação e recibo). A DCTFWeb continua manual.
 */
class ReinfStatus
{
    public const MAX_TENTATIVAS = 8;

    public const ESPERA_SEGUNDOS = 120;

    public const EXIGIDOS = ['R-4010', 'R-4099'];

    public const ROTULOS = ['na_fila' => 'Na fila do robô', 'aguardando' => 'Aguardando retorno', 'transmitido' => 'Transmitida e conferida',
        'pendente' => 'Não transmitida', 'erro' => 'Com problema'];

    /** Linhas do relatório como o smalot/pdfparser extrai (colunas fora de ordem). */
    public static function lerTexto(string $texto): array
    {
        $eventos = [];
        foreach (preg_split('/\R/u', $texto) as $l) {
            // "1 - EMPRESA\t09/202626.029.130/0001-40\tOficial20/10/2026 - 18:2012289914-10-2099-...SucessoFechamentoR-2099 - Fechamento..."
            if (! preg_match('/(\d{2}\/\d{4})\d{2}\.\d{3}\.\d{3}\/\d{4}-\d{2}\s+\S+?(\d{2}\/\d{2}\/\d{4} - \d{2}:\d{2})([\d-]*)(\D+?)'
                .'(Inclus[ãa]o|Fechamento|Altera[çc][ãa]o|Exclus[ãa]o|Reabertura|Retifica[çc][ãa]o)(R-\d{4}) - (.+)$/u', $l, $m)) {
                continue;
            }
            $eventos[] = ['competencia' => $m[1], 'enviado_em' => $m[2], 'recibo' => $m[3], 'situacao' => trim($m[4]),
                'tipo' => $m[5], 'evento' => $m[6], 'descricao' => trim($m[7])];
        }

        return $eventos;
    }

    public static function lerPdf(string $bytes): array
    {
        return self::lerTexto((new Parser)->parseContent($bytes)->getText());
    }

    public static function sucesso(string $situacao): bool
    {
        return (bool) preg_match('/^sucesso/iu', $situacao);
    }

    /** Situação dos eventos exigidos da competência (vale o último envio de cada um). */
    public static function situacao(array $eventos, string $comp): array
    {
        $ultimo = [];
        foreach ($eventos as $e) {
            if ($e['competencia'] === $comp) {
                $ultimo[$e['evento']] = $e;
            }
        }
        $ok = array_filter(self::EXIGIDOS, fn ($ev) => isset($ultimo[$ev]) && self::sucesso($ultimo[$ev]['situacao']));
        $erro = array_filter(self::EXIGIDOS, fn ($ev) => isset($ultimo[$ev]) && preg_match('/erro|invalid|rejeit/iu', $ultimo[$ev]['situacao']));
        $status = count($ok) === count(self::EXIGIDOS) ? 'transmitido' : ($erro ? 'erro' : ($ultimo ? 'aguardando' : 'nada'));

        return ['status' => $status, 'ultimos' => $ultimo, 'faltam' => array_values(array_diff(self::EXIGIDOS, $ok))];
    }

    public static function enfileirar(Aluguel $a, string $etapa, string $fluxo, ?string $por = null, int $esperar = 0): void
    {
        TarefaAgente::enfileirar('transmitir_reinf', [
            'aluguel_id' => $a->id, 'competencia' => $a->competencia, 'etapa' => $etapa, 'fluxo' => $fluxo,
            'esperar_segundos' => $esperar, 'opcao' => 'Entrega completa', 'transmitir_dctfweb' => false,
        ], $por ?? 'portal (automático)');
    }

    public static function retorno(Aluguel $a, TarefaAgente $t, bool $sucesso, ?string $mensagem, array $resultado, ?array $eventos = null): void
    {
        if (! $sucesso) {
            $a->update(['reinf_status' => 'erro', 'reinf_mensagem' => 'Erro no robô: '.$mensagem]);

            return;
        }
        $p = $t->payload;
        $pdf = $resultado['consulta_pdf_base64'] ?? null;
        $eventos ??= $pdf ? self::lerPdf(base64_decode($pdf)) : [];
        $sit = self::situacao($eventos, $a->competencia);
        $transmitindo = ($p['fluxo'] ?? '') === 'enviar';
        $dados = ['reinf_eventos' => array_values(array_filter($eventos, fn ($e) => $e['competencia'] === $a->competencia)), 'reinf_conferido_em' => now()];
        if (($p['etapa'] ?? '') === 'enviar') {
            $dados['reinf_enviado_em'] = now();
        }

        if ($sit['status'] === 'transmitido') {
            $recibos = implode(', ', array_map(fn ($ev) => "{$ev} recibo {$sit['ultimos'][$ev]['recibo']}", self::EXIGIDOS));
            $a->update($dados + ['reinf_status' => 'transmitido', 'reinf_mensagem' => "EFD-Reinf {$a->competencia} transmitida: {$recibos}. Falta só a DCTFWeb, que é com você."]);

            return;
        }
        if ($sit['status'] === 'erro') {
            $a->update($dados + ['reinf_status' => 'erro', 'reinf_mensagem' => 'Evento com erro/invalidado: '.implode(', ', array_map(
                fn ($ev) => "{$ev} ".$sit['ultimos'][$ev]['situacao'], array_keys(array_filter($sit['ultimos'], fn ($e) => ! self::sucesso($e['situacao'])))))
                .'. Veja a Consulta Envio dos Arquivos na Domínio.']);

            return;
        }
        $tent = $a->reinf_tentativas + 1;
        if ($transmitindo && $tent <= self::MAX_TENTATIVAS) {
            self::enfileirar($a, 'conferir', 'enviar', null, self::ESPERA_SEGUNDOS);
            $a->update($dados + ['reinf_status' => 'aguardando', 'reinf_tentativas' => $tent,
                'reinf_mensagem' => 'Enviada; aguardando o retorno da Receita (faltam '.implode(', ', $sit['faltam'])."; conferência {$tent})."]);
        } else {
            $a->update($dados + ['reinf_status' => 'pendente', 'reinf_mensagem' => $sit['status'] === 'nada'
                ? "Nenhum envio da EFD-Reinf {$a->competencia} na Domínio."
                : 'Ainda sem sucesso em: '.implode(', ', $sit['faltam']).'. Confira na Domínio e clique em Conferir de novo.']);
        }
    }
}
