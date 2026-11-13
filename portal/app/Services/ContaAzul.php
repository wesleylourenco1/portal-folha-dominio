<?php

namespace App\Services;

use App\Models\Configuracao;
use App\Models\Folha;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Integração com a API v2 do Conta Azul (OAuth 2.0, authorization code).
 * Docs: https://developers.contaazul.com — contas a pagar em POST /v1/financeiro/contas-a-pagar.
 * Tokens ficam criptografados na tabela configuracoes; o refresh_token muda a cada renovação.
 */
class ContaAzul
{
    public const AUTORIZAR = 'https://login.contaazul.com/#/oauth/authorize';
    public const TOKEN = 'https://api-v2.contaazul.com/oauth/token';
    public const API = 'https://api-v2.contaazul.com';
    public const ESCOPO = 'openid profile aws.cognito.signin.user.admin';

    public const CONFIG = ['contaazul_client_id', 'contaazul_redirect_uri', 'contaazul_fornecedor_id', 'contaazul_fornecedor_nome',
        'contaazul_conta_financeira', 'contaazul_categoria_folha', 'contaazul_categoria_adiantamento'];

    public static function redirectUri(): string
    {
        return Configuracao::ler('contaazul_redirect_uri') ?: route('contaazul.retorno');
    }

    public function configurado(): bool
    {
        return Configuracao::ler('contaazul_client_id') && Configuracao::tem('contaazul_client_secret');
    }

    public function conectado(): bool
    {
        return Configuracao::tem('contaazul_refresh_token');
    }

    /** Falta escolher algo para conseguir lançar? Retorna a lista do que falta. */
    public function pendencias(): array
    {
        $rotulos = ['contaazul_fornecedor_id' => 'fornecedor', 'contaazul_conta_financeira' => 'conta financeira',
            'contaazul_categoria_folha' => 'categoria da folha', 'contaazul_categoria_adiantamento' => 'categoria do adiantamento'];

        return array_values(array_filter($rotulos, fn ($c) => ! Configuracao::ler($c), ARRAY_FILTER_USE_KEY));
    }

    public function urlAutorizacao(string $state): string
    {
        return self::AUTORIZAR.'?'.http_build_query([
            'response_type' => 'code', 'client_id' => Configuracao::ler('contaazul_client_id'),
            'redirect_uri' => self::redirectUri(), 'state' => $state, 'scope' => self::ESCOPO,
        ]);
    }

    public function trocarCodigo(string $code): void
    {
        $this->pedirToken(['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => self::redirectUri()]);
    }

    public function desconectar(): void
    {
        Configuracao::apagar(['contaazul_access_token', 'contaazul_refresh_token', 'contaazul_token_expira']);
    }

    private function pedirToken(array $form): void
    {
        $r = Http::asForm()->timeout(30)
            ->withBasicAuth((string) Configuracao::ler('contaazul_client_id'), (string) Configuracao::ler('contaazul_client_secret'))
            ->post(self::TOKEN, $form);
        if (! $r->successful() || ! $r->json('access_token')) {
            $erro = $r->json('error_description') ?: $r->json('error') ?: 'HTTP '.$r->status();
            if (in_array($r->json('error_subtype'), ['access_revoked', 'invalid_refresh_token'], true)) {
                $this->desconectar();
                $erro .= ' Conecte o Conta Azul novamente.';
            }
            throw new RuntimeException('Conta Azul recusou a autenticação: '.$erro);
        }
        Configuracao::salvar([
            'contaazul_access_token' => $r->json('access_token'),
            'contaazul_refresh_token' => $r->json('refresh_token'),
            'contaazul_token_expira' => (string) (time() + (int) $r->json('expires_in', 3600) - 120),
        ]);
    }

    /** access_token válido; renova com o refresh_token quando vence (com trava, pois o refresh só vale uma vez). */
    private function token(): string
    {
        if (! $this->conectado()) {
            throw new RuntimeException('Conta Azul não conectado. Conecte em Configurações > Conta Azul.');
        }

        return Cache::lock('contaazul_token', 30)->block(25, function () {
            if ((int) Configuracao::ler('contaazul_token_expira', '0') <= time() || ! Configuracao::tem('contaazul_access_token')) {
                $this->pedirToken(['grant_type' => 'refresh_token', 'refresh_token' => Configuracao::ler('contaazul_refresh_token')]);
            }

            return (string) Configuracao::ler('contaazul_access_token');
        });
    }

    private function chamar(string $metodo, string $caminho, array $dados = []): array
    {
        $req = Http::withToken($this->token())->acceptJson()->timeout(60);
        $r = $metodo === 'get' ? $req->get(self::API.$caminho, $dados) : $req->post(self::API.$caminho, $dados);
        if ($r->status() === 429) {
            throw new RuntimeException('Conta Azul: muitas requisições, tente de novo em alguns minutos.');
        }
        if (! $r->successful()) {
            $erro = $r->json('error') ?: $r->json('message') ?: mb_substr($r->body(), 0, 300);
            throw new RuntimeException('Conta Azul respondeu '.$r->status().': '.(is_string($erro) ? $erro : json_encode($erro, JSON_UNESCAPED_UNICODE)));
        }

        return $r->json() ?? [];
    }

    public function categoriasDespesa(): array
    {
        $itens = $this->chamar('get', '/v1/categorias', ['tipo' => 'DESPESA', 'pagina' => 1, 'tamanho_pagina' => 500])['itens'] ?? [];

        return collect($itens)->map(fn ($c) => ['id' => $c['id'], 'nome' => $c['nome']])->sortBy('nome')->values()->all();
    }

    public function contasFinanceiras(): array
    {
        $itens = $this->chamar('get', '/v1/conta-financeira', ['pagina' => 1, 'tamanho_pagina' => 100, 'apenas_ativo' => 'true'])['itens'] ?? [];

        return collect($itens)->map(fn ($c) => ['id' => $c['id'], 'nome' => trim($c['nome'].' '.($c['banco'] ?? ''))])->values()->all();
    }

    public function fornecedores(): array
    {
        $itens = $this->chamar('get', '/v1/pessoas', ['tipo_perfil' => 'Fornecedor', 'pagina' => 1, 'tamanho_pagina' => 500])['items'] ?? [];

        return collect($itens)->map(fn ($p) => ['id' => $p['id'], 'nome' => $p['nome']])->sortBy('nome')->values()->all();
    }

    /** Cria o fornecedor genérico usado nos lançamentos da folha (CNPJ da própria empresa, se houver). */
    public function criarFornecedor(string $nome, ?string $cnpj = null): array
    {
        $corpo = ['nome' => $nome, 'tipo_pessoa' => 'Jurídica', 'perfis' => [['tipo_perfil' => 'Fornecedor']],
            'observacao' => 'Criado pelo Portal da Folha para lançar salários e adiantamentos.'];
        if ($cnpj) {
            $corpo['cnpj'] = $cnpj;
        }
        $p = $this->chamar('post', '/v1/pessoas', $corpo);

        return ['id' => $p['id'], 'nome' => $p['nome'] ?? $nome];
    }

    /** Monta o corpo da conta a pagar com o líquido total da folha. $vencimento em DD/MM/AAAA. */
    public static function payload(Folha $folha, string $vencimento): array
    {
        $valor = round((float) ($folha->dados['total_liquido'] ?? 0), 2);
        $conta = (string) Configuracao::ler('contaazul_conta_financeira');
        $categoria = (string) Configuracao::ler($folha->ehAdiantamento() ? 'contaazul_categoria_adiantamento' : 'contaazul_categoria_folha');
        [$d, $m, $a] = explode('/', $vencimento);
        [$cm, $ca] = explode('/', $folha->competencia);
        $qtd = count($folha->dados['funcionarios'] ?? []);

        return [
            'descricao' => $folha->descricaoContaAzul(),
            'data_competencia' => "{$ca}-{$cm}-01",
            'valor' => $valor,
            'conta_financeira' => $conta,
            'id_fornecedor' => (string) Configuracao::ler('contaazul_fornecedor_id'),
            'referencia_externa' => 'portal-folha-'.$folha->id,
            'observacao' => "Líquido total de {$qtd} funcionário(s) - {$folha->calculo} {$folha->competencia}. Lançado pelo Portal da Folha.",
            'rateio' => [['id_categoria' => $categoria, 'valor' => $valor]],
            'condicao_pagamento' => ['parcelas' => [[
                'descricao' => 'Parcela única',
                'data_vencimento' => "{$a}-{$m}-{$d}",
                'conta_financeira' => $conta,
                'detalhe_valor' => ['valor_bruto' => $valor],
            ]]],
        ];
    }

    /** Lança a folha como conta a pagar. Nunca lança duas vezes a mesma folha. */
    public function enviarFolha(Folha $folha, string $vencimento, string $usuario): Folha
    {
        return Cache::lock('contaazul_folha_'.$folha->id, 60)->block(30, function () use ($folha, $vencimento, $usuario) {
            $folha->refresh();
            if ($folha->contaazul_id) {
                throw new RuntimeException('Esta folha já foi lançada no Conta Azul em '.$folha->contaazul_enviado_em?->format('d/m/Y H:i').'.');
            }
            if ($falta = $this->pendencias()) {
                throw new RuntimeException('Configure no Conta Azul: '.implode(', ', $falta).'.');
            }
            if ((float) ($folha->dados['total_liquido'] ?? 0) <= 0) {
                throw new RuntimeException('Folha sem líquido a pagar.');
            }
            $corpo = self::payload($folha, $vencimento);
            $criado = $this->chamar('post', '/v1/financeiro/contas-a-pagar', $corpo);
            $folha->update([
                'contaazul_id' => $criado['id'] ?? 'sem-id', 'contaazul_valor' => $corpo['valor'], 'contaazul_vencimento' => $vencimento,
                'contaazul_enviado_em' => now(), 'contaazul_enviado_por' => $usuario,
            ]);

            return $folha;
        });
    }
}
