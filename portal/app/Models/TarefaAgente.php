<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Fila de tarefas executadas pelo agente na máquina da Domínio
 * (ex.: cadastrar uma admissão aprovada, gerar o adiantamento).
 */
class TarefaAgente extends Model
{
    protected $table = 'tarefas_agente';
    protected $fillable = ['tipo', 'status', 'payload', 'resultado', 'mensagem', 'solicitado_por', 'iniciada_em', 'concluida_em'];
    protected $casts = [
        'payload' => 'encrypted:array',
        'resultado' => 'array',
        'iniciada_em' => 'datetime',
        'concluida_em' => 'datetime',
    ];

    public const TIPOS = ['cadastrar_admissao', 'gerar_adiantamento', 'importar_lancamentos', 'consultar_esocial', 'lancar_nota_aluguel'];

    public static function enfileirar(string $tipo, array $payload = [], ?string $por = null): self
    {
        return static::create(['tipo' => $tipo, 'payload' => $payload, 'solicitado_por' => $por]);
    }

    /** Pega a próxima tarefa pendente e marca como "executando" (atômico). */
    public static function proxima(): ?self
    {
        return DB::transaction(function () {
            $t = static::where('status', 'pendente')->orderBy('id')->lockForUpdate()->first();
            $t?->update(['status' => 'executando', 'iniciada_em' => now()]);

            return $t;
        });
    }

    public static function pendente(string $tipo): ?self
    {
        return static::where('tipo', $tipo)->whereIn('status', ['pendente', 'executando'])->latest('id')->first();
    }
}
