<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Admissao extends Model
{
    protected $table = 'admissoes';

    protected $fillable = ['status', 'dados', 'extracao', 'mensagem', 'log', 'criado_por'];

    // dados pessoais criptografados no banco
    protected $casts = ['dados' => 'encrypted:array', 'extracao' => 'encrypted:array'];

    public const STATUS = [
        'extraindo' => 'Lendo documentos',
        'revisar' => 'Aguardando revisão do RH',
        'erro_extracao' => 'Erro na leitura',
        'aprovado' => 'Na fila do robô',
        'cadastrando' => 'Cadastrando na Domínio',
        'aguardando_esocial' => 'Aguardando retorno do eSocial',
        'concluido' => 'Concluído (eSocial OK)',
        'erro_esocial' => 'eSocial rejeitou',
        'erro_cadastro' => 'Erro no cadastro',
    ];

    public const EDITAVEIS = ['revisar', 'erro_extracao', 'erro_cadastro'];

    public function documentos(): HasMany
    {
        return $this->hasMany(AdmissaoDocumento::class);
    }

    public function autor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'criado_por');
    }

    public function registrar(string $texto): void
    {
        $this->log = ($this->log ?? '').'['.now()->format('d/m/Y H:i').'] '.$texto."\n";
        $this->save();
    }

    public function statusTexto(): string
    {
        return self::STATUS[$this->status] ?? $this->status;
    }
}
