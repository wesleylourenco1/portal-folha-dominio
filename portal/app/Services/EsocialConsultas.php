<?php

namespace App\Services;

use App\Models\Admissao;
use App\Models\Configuracao;
use App\Models\Folha;
use App\Models\Funcionario;
use App\Models\TarefaAgente;
use Illuminate\Support\Carbon;

/**
 * Planeja e aplica as consultas ao eSocial feitas pelo robô com o certificado da empresa.
 *
 * Regras do eSocial (MOD v1.16, 7.7): até 10 acessos/dia por empregador, nada entre os dias 1 e 7,
 * janela de até 31 dias pela data de RECEPÇÃO do evento, data fim pelo menos 1 hora antes de agora.
 * Prioridade: admitidos nos últimos 90 dias sem recibo da admissão; depois rodízio dos demais
 * (cada um consultado no máximo a cada 30 dias).
 */
class EsocialConsultas
{
    public const LIMITE_EMPREGADOR = 10;

    public function ativo(): bool
    {
        return Configuracao::ler('esocial_ativo', 'nao') === 'sim';
    }

    public function porDia(): int
    {
        return max(1, min(self::LIMITE_EMPREGADOR - 1, (int) Configuracao::ler('esocial_consultas_dia', '8')));
    }

    public function cnpj(): ?string
    {
        $cnpj = Configuracao::ler('esocial_cnpj') ?: Folha::latest('updated_at')->value('cnpj');

        return $cnpj ? preg_replace('/\D/', '', $cnpj) : null;
    }

    /** Dias 1 a 7 o eSocial não aceita consultas. */
    public function diaPermitido(?Carbon $agora = null): bool
    {
        return ($agora ?? now())->day >= 8;
    }

    /** Consultas já pedidas hoje (somando as tarefas do dia). */
    public function usadasHoje(): int
    {
        return TarefaAgente::where('tipo', 'consultar_esocial')->where('created_at', '>=', now()->startOfDay())
            ->get()->sum(fn ($t) => count($t->payload['consultas'] ?? []));
    }

    public function janela(Funcionario $f, string $motivo, ?Carbon $agora = null): ?array
    {
        $agora ??= now();
        $limite = $agora->copy()->subHours(2)->startOfMinute();          // eSocial: até 1 h antes (margem de 2 h)
        if ($motivo === 'admissao') {
            $adm = Carbon::createFromFormat('d/m/Y', $f->admissao)->startOfDay();
            $ini = $adm->copy()->subDays(20);                                // S-2200 é enviado até a véspera do início
            $fim = $adm->copy()->addDays(10)->endOfDay();
            $fim = $fim->lt($limite) ? $fim : $limite;
        } else {
            $fim = $limite;
            $ini = $fim->copy()->subDays(30);
        }

        return $fim->gt($ini) ? ['dt_ini' => $ini->format('Y-m-d\TH:i:s'), 'dt_fim' => $fim->format('Y-m-d\TH:i:s')] : null;
    }

    /** Escolhe quem consultar agora, dentro do saldo do dia. */
    public function planejar(int $saldo, ?Carbon $agora = null): array
    {
        $agora ??= now();
        $consultas = [];
        $ativos = Funcionario::ativos()->whereNotNull('cpf')->get()
            ->filter(fn ($f) => strlen(preg_replace('/\D/', '', $f->cpf)) === 11);

        $novos = $ativos->filter(function ($f) use ($agora) {
            $adm = $f->admissao ? Carbon::createFromFormat('d/m/Y', $f->admissao) : null;

            return $adm && ! $f->esocial_admissao_ok && $adm->gte($agora->copy()->subDays(90))
                && (! $f->esocial_consultado_em || $f->esocial_consultado_em->lt($agora->copy()->startOfDay()));
        })->sortBy(fn ($f) => Carbon::createFromFormat('d/m/Y', $f->admissao)->timestamp);

        $rodizio = $ativos->reject(fn ($f) => $novos->contains('id', $f->id))
            ->filter(fn ($f) => ! $f->esocial_consultado_em || $f->esocial_consultado_em->lt($agora->copy()->subDays(30)))
            ->sortBy(fn ($f) => $f->esocial_consultado_em?->timestamp ?? 0);

        foreach ([['admissao', $novos], ['rotina', $rodizio]] as [$motivo, $grupo]) {
            foreach ($grupo as $f) {
                if (count($consultas) >= $saldo) {
                    break 2;
                }
                if ($j = $this->janela($f, $motivo, $agora)) {
                    $consultas[] = ['funcionario_id' => $f->id, 'cpf' => preg_replace('/\D/', '', $f->cpf), 'motivo' => $motivo] + $j;
                }
            }
        }

        return $consultas;
    }

    /** Chamado a cada consulta do robô ao portal: agenda as consultas do dia uma vez, a partir das 8 h. */
    public function agendarDoDia(): ?TarefaAgente
    {
        if (! $this->ativo() || ! $this->diaPermitido() || now()->hour < 8 || ! $this->cnpj()
            || TarefaAgente::pendente('consultar_esocial')
            || TarefaAgente::where('tipo', 'consultar_esocial')->where('created_at', '>=', now()->startOfDay())
                ->where('solicitado_por', 'agendamento automático')->exists()) {
            return null;
        }
        $consultas = $this->planejar(max(0, $this->porDia() - $this->usadasHoje()));

        return $consultas ? $this->enfileirar($consultas, 'agendamento automático') : null;
    }

    public function enfileirar(array $consultas, string $por): TarefaAgente
    {
        return TarefaAgente::enfileirar('consultar_esocial', [
            'cnpj' => $this->cnpj(), 'ambiente' => Configuracao::ler('esocial_ambiente', 'producao'), 'consultas' => $consultas,
        ], $por);
    }

    /** Consulta manual de um funcionário (botão na tela), respeitando o saldo do dia. */
    public function consultarAgora(Funcionario $f, string $por): string
    {
        if (! $this->cnpj()) {
            return 'CNPJ da empresa desconhecido: importe uma folha ou informe o CNPJ em Configurações.';
        }
        if (! $this->diaPermitido()) {
            return 'O eSocial não aceita consultas entre os dias 1 e 7 do mês.';
        }
        if ($this->usadasHoje() >= $this->porDia()) {
            return 'O limite de consultas ao eSocial de hoje já foi usado. Tente amanhã.';
        }
        $adm = $f->admissao ? Carbon::createFromFormat('d/m/Y', $f->admissao) : null;
        $motivo = $adm && ! $f->esocial_admissao_ok && $adm->gte(now()->subDays(90)) ? 'admissao' : 'rotina';
        $j = $this->janela($f, $motivo);
        if (! $j) {
            return 'Ainda não dá para consultar (o eSocial só mostra eventos recebidos até 1 hora atrás).';
        }
        $this->enfileirar([['funcionario_id' => $f->id, 'cpf' => preg_replace('/\D/', '', $f->cpf), 'motivo' => $motivo] + $j], $por);

        return "Consulta de {$f->nome} enviada ao robô.";
    }

    /** Grava o que o robô trouxe do eSocial e conclui admissões confirmadas. */
    public function aplicar(array $resultado): void
    {
        foreach ($resultado['itens'] ?? [] as $i) {
            $f = Funcionario::find($i['funcionario_id'] ?? 0);
            if (! $f) {
                continue;
            }
            $recibos = $i['recibos'] ?? [];
            $dados = ['esocial_consultado_em' => now(), 'esocial_mensagem' => $i['descricao'] ?? null];
            if (in_array($i['codigo'] ?? '', ['201', '203'], true)) {
                $dados += ['esocial_situacao' => 'recebido', 'esocial_eventos' => (int) ($i['total'] ?? count($recibos)),
                    'esocial_ultimo_evento' => $i['ultimo'] ?? null, 'esocial_recibo' => end($recibos) ?: null];
                if (($i['motivo'] ?? '') === 'admissao' && $recibos) {
                    $dados += ['esocial_admissao_ok' => true, 'esocial_recibo_admissao' => $recibos[0]];
                }
            } elseif (($i['codigo'] ?? '') === '406') {
                $dados += ['esocial_situacao' => ($i['motivo'] ?? '') === 'admissao' ? 'aguardando' : 'sem_eventos', 'esocial_eventos' => 0];
            } else {
                $dados += ['esocial_situacao' => 'erro'];
            }
            $f->update($dados);
            if (! empty($dados['esocial_admissao_ok'])) {
                $this->concluirAdmissao($f);
            }
        }
        $status = $resultado['parado'] ? 'Parou por hoje: '.$resultado['parado'] : 'OK';
        Configuracao::salvar([
            'esocial_status' => now()->format('d/m/Y H:i').' - '.count($resultado['itens'] ?? []).' consulta(s). '.$status,
            'esocial_validade_certificado' => $resultado['validade_certificado'] ?? Configuracao::ler('esocial_validade_certificado'),
        ]);
    }

    private function concluirAdmissao(Funcionario $f): void
    {
        $cpf = preg_replace('/\D/', '', $f->cpf);
        foreach (Admissao::where('status', 'aguardando_esocial')->get() as $adm) {
            if (preg_replace('/\D/', '', $adm->dados['cpf'] ?? '') !== $cpf) {
                continue;
            }
            $adm->update(['status' => 'concluido', 'mensagem' => "eSocial aceitou a admissão (recibo {$f->esocial_recibo_admissao})."]);
            $adm->registrar("eSocial: admissão aceita, recibo {$f->esocial_recibo_admissao}");
            try {
                app(Notificador::class)->enviar("Admissão aceita no eSocial: {$f->nome}",
                    "A admissão de {$f->nome} foi aceita pelo eSocial.\nRecibo: {$f->esocial_recibo_admissao}");
            } catch (\Throwable) {
                // e-mail é aviso extra; o status no portal já foi atualizado
            }
        }
    }
}
