<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Funcionario extends Model
{
    protected $fillable = [
        'codigo', 'nome', 'cpf', 'cargo_codigo', 'cargo', 'cbo', 'admissao', 'salario', 'situacao', 'ativo',
        'local', 'chave_pix', 'vale_transporte', 'vr_mes', 'desconto_vr', 'comissionado', 'origem', 'visto_em',
        'esocial_situacao', 'esocial_admissao_ok', 'esocial_recibo_admissao', 'esocial_recibo', 'esocial_eventos',
        'esocial_ultimo_evento', 'esocial_mensagem', 'esocial_consultado_em',
    ];

    protected $casts = [
        'ativo' => 'boolean',
        'comissionado' => 'boolean',
        'salario' => 'float',
        'vale_transporte' => 'float',
        'vr_mes' => 'float',
        'desconto_vr' => 'float',
        'chave_pix' => 'encrypted',
        'visto_em' => 'datetime',
        'esocial_admissao_ok' => 'boolean',
        'esocial_consultado_em' => 'datetime',
    ];

    public function scopeAtivos(Builder $q): Builder
    {
        return $q->where('ativo', true);
    }
}
