<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LancamentoCompetencia extends Model
{
    protected $table = 'lancamento_competencias';

    protected $primaryKey = 'competencia';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = ['competencia', 'status', 'por', 'em'];

    protected $casts = ['em' => 'datetime'];

    public static function de(string $competencia): self
    {
        return static::firstOrNew(['competencia' => $competencia], ['status' => 'aberta']);
    }

    public function fechada(): bool
    {
        return $this->status === 'fechada';
    }
}
