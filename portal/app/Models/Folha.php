<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class Folha extends Model
{
    protected $fillable = ['competencia', 'empresa_codigo', 'empresa_nome', 'cnpj', 'calculo', 'dados', 'origem', 'importado_por'];

    // salários e CPFs criptografados no banco
    protected $casts = ['dados' => 'encrypted:array'];

    public function ehAdiantamento(): bool
    {
        return str_contains(mb_strtoupper($this->calculo), 'ADIANT');
    }

    /** Adiantamento vence no dia configurado (padrão 20) da competência. */
    public function vencimento(): ?string
    {
        if (! $this->ehAdiantamento()) {
            return null;
        }
        $dia = (int) (Configuracao::ler('adiantamento_dia_vencimento', '20') ?: 20);

        return sprintf('%02d/%s', $dia, $this->competencia);
    }

    public function pasta(): string
    {
        return 'folhas/'.$this->id;
    }

    public function temArquivo(string $tipo): bool
    {
        return Storage::disk('local')->exists($this->pasta()."/{$tipo}.pdf");
    }

    /** Ordena competências MM/AAAA da mais recente para a mais antiga. */
    public function chaveOrdem(): string
    {
        return substr($this->competencia, 3).substr($this->competencia, 0, 2);
    }
}
