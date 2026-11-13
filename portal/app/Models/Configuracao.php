<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

/**
 * Configurações do portal guardadas no banco. Segredos (chaves de API, senhas)
 * são criptografados com a APP_KEY e nunca voltam para a tela.
 */
class Configuracao extends Model
{
    protected $table = 'configuracoes';
    protected $primaryKey = 'chave';
    public $incrementing = false;
    protected $keyType = 'string';
    protected $fillable = ['chave', 'valor', 'segredo'];

    public const SEGREDOS = ['ia_chave', 'smtp_senha', 'dominio_senha'];

    public static function ler(string $chave, ?string $padrao = null): ?string
    {
        $c = static::find($chave);
        if (! $c || $c->valor === null || $c->valor === '') {
            return $padrao;
        }

        return $c->segredo ? Crypt::decryptString($c->valor) : $c->valor;
    }

    public static function tem(string $chave): bool
    {
        return (bool) static::find($chave)?->valor;
    }

    /** Segredo em branco = manter o atual. */
    public static function salvar(array $valores): void
    {
        foreach ($valores as $chave => $valor) {
            $segredo = in_array($chave, self::SEGREDOS, true);
            if ($segredo && ($valor === null || $valor === '')) {
                continue;
            }
            static::updateOrCreate(['chave' => $chave], [
                'valor' => $segredo ? Crypt::encryptString((string) $valor) : $valor,
                'segredo' => $segredo,
            ]);
        }
    }
}
