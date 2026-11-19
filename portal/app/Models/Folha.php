<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class Folha extends Model
{
    protected $fillable = ['competencia', 'empresa_codigo', 'empresa_nome', 'cnpj', 'calculo', 'dados', 'origem', 'importado_por',
        'contaazul_id', 'contaazul_valor', 'contaazul_vencimento', 'contaazul_enviado_em', 'contaazul_enviado_por',
        'esocial_status', 'esocial_mensagem', 'esocial_empregados', 'esocial_transmitido_em', 'esocial_transmitido_por', 'esocial_conferido_em', 'esocial_fechado_em', 'esocial_tentativas'];

    // salários e CPFs criptografados no banco
    protected $casts = ['dados' => 'encrypted:array', 'contaazul_enviado_em' => 'datetime', 'contaazul_valor' => 'float',
        'esocial_empregados' => 'encrypted:array', 'esocial_transmitido_em' => 'datetime', 'esocial_conferido_em' => 'datetime', 'esocial_fechado_em' => 'datetime'];

    /**
     * Vencimento do pagamento (DD/MM/AAAA): adiantamento no dia configurado da competência;
     * folha mensal no 5º dia útil do mês seguinte (CLT art. 459; sábado conta como dia útil).
     */
    public function vencimentoPagamento(): string
    {
        if ($v = $this->vencimento()) {
            return $v;
        }
        [$mes, $ano] = array_map('intval', explode('/', $this->competencia));
        $dia = mktime(0, 0, 0, $mes + 1, 1, $ano);
        $uteis = 0;
        while (true) {
            $feriado = in_array(date('d/m', $dia), ['01/01', '21/04', '01/05', '07/09', '12/10', '02/11', '15/11', '20/11', '25/12'], true);
            if (date('w', $dia) !== '0' && ! $feriado && ++$uteis === 5) {
                return date('d/m/Y', $dia);
            }
            $dia = strtotime('+1 day', $dia);
        }
    }

    public function descricaoContaAzul(): string
    {
        return ($this->ehAdiantamento() ? 'Adiantamento' : 'Folha').' '.$this->competencia.' - '.$this->empresa_nome;
    }

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
