<?php

namespace App\Services;

use App\Models\Configuracao;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail;
use RuntimeException;

/** Envia e-mails usando o SMTP configurado na tela de Configurações. */
class Notificador
{
    public function enviar(string $assunto, string $corpo, ?string $destino = null): void
    {
        $servidor = Configuracao::ler('smtp_servidor');
        $usuario = Configuracao::ler('smtp_usuario');
        $senha = Configuracao::ler('smtp_senha');
        $destino ??= Configuracao::ler('email_destino');
        if (! $servidor || ! $usuario || ! $senha || ! $destino) {
            throw new RuntimeException('E-mail não configurado (servidor, usuário, senha e destinatário).');
        }
        $porta = (int) (Configuracao::ler('smtp_porta', '587') ?: 587);
        Config::set('mail.mailers.portal', [
            'transport' => 'smtp', 'host' => $servidor, 'port' => $porta,
            'scheme' => $porta === 465 ? 'smtps' : 'smtp', 'username' => $usuario, 'password' => $senha, 'timeout' => 30,
        ]);
        $remetente = Configuracao::ler('smtp_remetente') ?: $usuario;

        Mail::mailer('portal')->raw($corpo, function ($m) use ($assunto, $destino, $remetente) {
            $m->to($destino)->from($remetente)->subject($assunto);
        });
    }

    /** Envia sem derrubar o fluxo principal; devolve a mensagem de erro, se houver. */
    public function tentar(string $assunto, string $corpo): ?string
    {
        try {
            $this->enviar($assunto, $corpo);

            return null;
        } catch (\Throwable $e) {
            return $e->getMessage();
        }
    }
}
