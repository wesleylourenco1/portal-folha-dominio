<?php

namespace App\Services;

use App\Models\Configuracao;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Certificado A1 (e-CNPJ) usado pelo robô nas consultas ao eSocial.
 *
 * Arquivo e senha ficam CRIPTOGRAFADOS no servidor (APP_KEY): o .pfx em storage/app/private/certificados
 * e a senha na tabela configuracoes (segredo). Nunca voltam para a tela; só o robô os recebe,
 * por HTTPS e com o token do agente, na hora de consultar.
 */
class CertificadoEsocial
{
    public const ARQUIVO = 'certificados/esocial.pfx.enc';

    public function existe(): bool
    {
        return Storage::disk('local')->exists(self::ARQUIVO) && Configuracao::tem('esocial_certificado_senha');
    }

    /** Valida (senha, CNPJ) e guarda criptografado. Retorna os dados públicos do certificado. */
    public function salvar(string $conteudo, string $senha, ?string $cnpjEmpresa, string $por): array
    {
        $info = $this->ler($conteudo, $senha);
        if ($cnpjEmpresa && $info['cnpj'] && substr($info['cnpj'], 0, 8) !== substr($cnpjEmpresa, 0, 8)) {
            throw new RuntimeException("Este certificado é do CNPJ {$info['cnpj']}, diferente da empresa ({$cnpjEmpresa}). O eSocial recusaria a consulta.");
        }
        if ($info['validade'] && $info['validade'] < time()) {
            throw new RuntimeException('Este certificado está vencido desde '.date('d/m/Y', $info['validade']).'.');
        }
        Storage::disk('local')->put(self::ARQUIVO, Crypt::encryptString(base64_encode($conteudo)));
        Configuracao::salvar([
            'esocial_certificado_senha' => $senha,
            'esocial_certificado_titular' => $info['titular'],
            'esocial_validade_certificado' => $info['validade'] ? date('d/m/Y', $info['validade']) : null,
            'esocial_certificado_enviado' => "por {$por} em ".now()->format('d/m/Y H:i'),
        ]);

        return $info;
    }

    public function remover(): void
    {
        Storage::disk('local')->delete(self::ARQUIVO);
        Configuracao::apagar(['esocial_certificado_senha', 'esocial_certificado_titular', 'esocial_validade_certificado', 'esocial_certificado_enviado']);
    }

    /** Para o robô: arquivo (base64) e senha em claro. Só usado pela API autenticada do agente. */
    public function paraOAgente(): ?array
    {
        if (! $this->existe()) {
            return null;
        }
        Configuracao::salvar(['esocial_certificado_ultimo_uso' => now()->format('d/m/Y H:i')]);

        return [
            'pfx_base64' => Crypt::decryptString(Storage::disk('local')->get(self::ARQUIVO)),
            'senha' => Configuracao::ler('esocial_certificado_senha'),
            'titular' => Configuracao::ler('esocial_certificado_titular'),
        ];
    }

    /** Abre o .pfx com a senha. Senha errada => erro; algoritmo antigo não suportado => aceita e o robô valida. */
    public function ler(string $conteudo, string $senha): array
    {
        if (openssl_pkcs12_read($conteudo, $c, $senha)) {
            $x = openssl_x509_parse($c['cert']) ?: [];
            $titular = $x['subject']['CN'] ?? 'certificado';
            preg_match('/(\d{14})\s*$/', $titular, $m);

            return ['titular' => $titular, 'cnpj' => $m[1] ?? null, 'validade' => $x['validTo_time_t'] ?? null];
        }
        $erros = '';
        while ($e = openssl_error_string()) {
            $erros .= $e.' ';
        }
        if (stripos($erros, 'unsupported') !== false || stripos($erros, 'algorithm') !== false) {
            return ['titular' => 'a validar pelo robô (criptografia antiga do arquivo)', 'cnpj' => null, 'validade' => null];
        }
        throw new RuntimeException('Não foi possível abrir o certificado: senha incorreta ou arquivo inválido (precisa ser A1 .pfx/.p12).');
    }
}
