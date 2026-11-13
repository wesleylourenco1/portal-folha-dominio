<?php

namespace App\Http\Controllers;

use App\Models\Configuracao;
use App\Services\ExtratorDocumentos;
use App\Services\Notificador;
use Illuminate\Http\Request;

class ConfiguracaoController extends Controller
{
    public const CHAVES = [
        'ia_provedor', 'ia_modelo', 'ia_chave',
        'smtp_servidor', 'smtp_porta', 'smtp_usuario', 'smtp_senha', 'smtp_remetente', 'email_destino',
    ];

    public function index(?string $mensagem = null, bool $erro = false)
    {
        $v = [];
        foreach (self::CHAVES as $c) {
            $v[$c] = in_array($c, Configuracao::SEGREDOS, true) ? '' : Configuracao::ler($c, '');
        }

        return view('configuracoes', [
            'v' => $v,
            'salvos' => collect(Configuracao::SEGREDOS)->mapWithKeys(fn ($c) => [$c => Configuracao::tem($c)]),
            'provedores' => ExtratorDocumentos::PROVEDORES,
            'mensagem' => $mensagem ?? session('mensagem'), 'erro' => $erro,
        ]);
    }

    public function salvar(Request $request, ExtratorDocumentos $ia, Notificador $notificador)
    {
        $request->validate([
            'ia_provedor' => 'nullable|in:'.implode(',', array_keys(ExtratorDocumentos::PROVEDORES)),
            'smtp_porta' => 'nullable|integer|min:1|max:65535',
            'email_destino' => 'nullable|email',
        ]);
        Configuracao::salvar($request->only(self::CHAVES));

        try {
            $msg = match ($request->input('acao')) {
                'testar_ia' => $ia->testarConexao(Configuracao::ler('ia_provedor', 'anthropic'), (string) Configuracao::ler('ia_chave')),
                'testar_email' => tap('E-mail de teste enviado para '.Configuracao::ler('email_destino').'.',
                    fn () => $notificador->enviar('Teste do portal', 'Se você recebeu este e-mail, o envio está configurado.')),
                default => 'Configurações salvas.',
            };
        } catch (\Throwable $e) {
            return $this->index('Configurações salvas, mas o teste falhou: '.$e->getMessage(), true);
        }

        return $this->index($msg);
    }
}
