<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdmissaoDocumento extends Model
{
    protected $table = 'admissao_documentos';
    protected $fillable = ['admissao_id', 'nome_original', 'caminho', 'mime'];

    public function admissao(): BelongsTo
    {
        return $this->belongsTo(Admissao::class);
    }
}
