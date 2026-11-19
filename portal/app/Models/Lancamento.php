<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Lancamento extends Model
{
    protected $fillable = ['competencia', 'funcionario_codigo', 'dados', 'atualizado_por'];

    protected $casts = ['dados' => 'array'];
}
