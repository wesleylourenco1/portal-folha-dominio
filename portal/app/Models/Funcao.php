<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Funcao extends Model
{
    protected $table = 'funcoes';
    protected $fillable = ['codigo', 'nome', 'cbo', 'salario', 'tipo_salario', 'horas_mes', 'horario', 'departamento'];
    protected $casts = ['salario' => 'decimal:2', 'horas_mes' => 'integer'];
}
