<?php

namespace App\Services;

use App\Models\Folha;
use App\Models\TarefaAgente;
use Smalot\PdfParser\Parser;

/**
 * eSocial da folha mensal, em etapas (a Domínio não deixa o S-1299 junto com S-1200/S-1210):
 *   1. "remuneracao": o robô envia S-1200 e S-1210;
 *   2. o portal manda conferir (Relação de Status dos Eventos Periódicos) até todos ficarem "Validado";
 *   3. "fechamento": o robô envia o S-1299 (DCTFWeb nunca);
 *   4. o portal manda conferir até o fechamento estar validado (a Domínio só emite o Analítico INSS com S-1299 validado).
 */
class EsocialStatusFolha
{
    public const MAX_TENTATIVAS = 8;          // conferências automáticas por etapa

    public const ESPERA_SEGUNDOS = 180;       // entre conferências

    public const ROTULOS = [
        'na_fila' => 'Na fila do robô', 'aguardando' => 'Aguardando validação', 'fechando' => 'Fechamento enviado',
        'validado' => 'Remunerações validadas', 'transmitido' => 'Transmitido e conferido', 'pendente' => 'Pendente', 'erro' => 'Com problema',
    ];

    /** Texto como o smalot/pdfparser extrai a "Relação de Status dos Eventos Periódicos". */
    public static function lerTexto(string $texto): array
    {
        preg_match('/Compet[êe]ncia:\s*(\d{2}\/\d{4})/u', $texto, $c);
        $empregados = [];
        foreach (preg_split('/\R/u', $texto) as $linha) {
            // "20\tValidadoValidado09/2026114.951.129-00BRUNO BUENO DE CARVALHO20"
            if (! preg_match('/^\s*(\d+)\s+(.*?)(\d{2}\/\d{4})(\d{3}\.\d{3}\.\d{3}-\d{2})(.+?)\s*\d*\s*$/u', $linha, $m)) {
                continue;
            }
            $st = array_values(array_filter(array_map('trim', preg_split('/(?<=\p{Ll})(?=\p{Lu})/u', trim($m[2]))), 'strlen'));
            // o PDF traz Pagamento antes de Remuneração (colunas da direita para a esquerda)
            $pagamento = count($st) === 2 ? $st[0] : '';
            $remuneracao = count($st) === 2 ? $st[1] : ($st[0] ?? '');
            $empregados[] = ['codigo' => $m[1], 'nome' => trim(preg_replace('/\d+$/', '', trim($m[5]))), 'cpf' => $m[4],
                'remuneracao' => $remuneracao, 'pagamento' => $pagamento];
        }

        return ['competencia' => $c[1] ?? null, 'empregados' => $empregados];
    }

    public static function lerPdf(string $bytes): array
    {
        return self::lerTexto((new Parser)->parseContent($bytes)->getText());
    }

    public static function situacao(array $empregados): array
    {
        $com = array_filter($empregados, fn ($e) => $e['remuneracao'] !== '' || $e['pagamento'] !== '');
        $ok = array_filter($com, fn ($e) => self::validado($e['remuneracao']) && self::validado($e['pagamento']));
        $erro = array_filter($com, fn ($e) => preg_match('/rejeit|inv[áa]lid|erro/iu', $e['remuneracao'].' '.$e['pagamento']));
        $status = ! $com ? 'nada' : ($erro ? 'erro' : (count($ok) === count($com) ? 'validado' : 'aguardando'));

        return ['status' => $status, 'total' => count($com), 'validados' => count($ok), 'com_erro' => count($erro)];
    }

    public static function validado(string $s): bool
    {
        return (bool) preg_match('/^(validado|processado|aceito)/iu', $s);
    }

    /** Coloca uma etapa na fila do robô. */
    public static function enfileirar(Folha $f, string $etapa, string $fluxo, ?string $por = null, int $esperar = 0, bool $fechamentoEnviado = false): void
    {
        TarefaAgente::enfileirar('transmitir_esocial_folha', [
            'folha_id' => $f->id, 'competencia' => $f->competencia, 'etapa' => $etapa, 'fluxo' => $fluxo,
            'fechamento_enviado' => $fechamentoEnviado || $etapa === 'fechamento', 'esperar_segundos' => $esperar,
            'transmitir_dctfweb' => false,
        ], $por ?? 'portal (automático)');
    }

    /** Retorno do robô: atualiza a folha e decide o próximo passo do fluxo. */
    public static function retorno(Folha $f, TarefaAgente $t, bool $sucesso, ?string $mensagem, array $resultado, ?array $lido = null): void
    {
        $p = $t->payload;
        if (! $sucesso) {
            $f->update(['esocial_status' => 'erro', 'esocial_mensagem' => 'Erro no robô: '.$mensagem]);

            return;
        }
        $lido ??= self::lerPdf(base64_decode($resultado['status_pdf_base64'] ?? ''));
        $sit = self::situacao($lido['empregados']);
        $transmitindo = ($p['fluxo'] ?? '') === 'transmitir';
        $fechEnviado = ($p['fechamento_enviado'] ?? false) || ($p['etapa'] ?? '') === 'fechamento';
        $dados = ['esocial_empregados' => $lido['empregados'], 'esocial_conferido_em' => now()];
        if (($p['etapa'] ?? '') !== 'conferir') {
            $dados['esocial_transmitido_em'] = now();
        }
        $resumo = "{$sit['validados']} de {$sit['total']} empregados com remuneração e pagamento validados";

        if (! empty($resultado['fechamento_validado'])) {
            $f->update($dados + ['esocial_status' => 'transmitido', 'esocial_fechado_em' => $f->esocial_fechado_em ?? now(),
                'esocial_mensagem' => "eSocial completo: {$resumo} e fechamento (S-1299) validado. Falta só a DCTFWeb, que é com você."]);

            return;
        }
        if ($sit['status'] === 'erro') {
            $f->update($dados + ['esocial_status' => 'erro',
                'esocial_mensagem' => "{$sit['com_erro']} empregado(s) com evento rejeitado/inválido — veja abaixo e o Painel de Pendências da Domínio. O fechamento não foi enviado."]);

            return;
        }
        $tent = $f->esocial_tentativas + 1;
        $podeTentar = $transmitindo && $tent <= self::MAX_TENTATIVAS;

        if ($sit['status'] === 'validado' && $transmitindo && ! $fechEnviado) {
            self::enfileirar($f, 'fechamento', 'transmitir');
            $f->update($dados + ['esocial_status' => 'na_fila', 'esocial_tentativas' => 0,
                'esocial_mensagem' => "S-1200/S-1210 validados ({$resumo}). Enviando o fechamento S-1299…"]);
        } elseif ($sit['status'] === 'validado' && $fechEnviado) {
            if ($podeTentar) {
                self::enfileirar($f, 'conferir', 'transmitir', null, self::ESPERA_SEGUNDOS, true);
            }
            $f->update($dados + ['esocial_status' => $podeTentar ? 'fechando' : 'pendente', 'esocial_tentativas' => $tent,
                'esocial_mensagem' => $podeTentar ? "Fechamento S-1299 enviado; aguardando a validação (conferência {$tent})."
                    : 'Fechamento enviado, mas ainda não validado. Confira o Painel de Pendências da Domínio e clique em Conferir de novo.']);
        } elseif ($sit['status'] === 'validado') {
            $f->update($dados + ['esocial_status' => 'validado',
                'esocial_mensagem' => "{$resumo}. O fechamento (S-1299) ainda não está validado."]);
        } elseif ($sit['status'] === 'aguardando' || ($sit['status'] === 'nada' && $transmitindo)) {
            if ($podeTentar) {
                self::enfileirar($f, 'conferir', 'transmitir', null, self::ESPERA_SEGUNDOS, $fechEnviado);
            }
            $f->update($dados + ['esocial_status' => $podeTentar ? 'aguardando' : 'pendente', 'esocial_tentativas' => $tent,
                'esocial_mensagem' => $podeTentar ? "Aguardando o eSocial validar: {$resumo} (conferência {$tent})."
                    : "Ainda não validou: {$resumo}. Veja o Painel de Pendências da Domínio e clique em Conferir de novo."]);
        } else {
            $f->update($dados + ['esocial_status' => 'pendente', 'esocial_mensagem' => 'Nenhum evento desta competência foi enviado ainda.']);
        }
    }
}
