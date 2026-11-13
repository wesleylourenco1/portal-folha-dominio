<?php

namespace Tests\Feature;

use App\Models\Configuracao;
use App\Models\Folha;
use App\Models\User;
use App\Services\ExtratoParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ContaAzulTest extends TestCase
{
    use RefreshDatabase;

    private const API = 'https://api-v2.contaazul.com';

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['name' => 'rh']));
    }

    private function folha(string $calculo = 'Folha Mensal', string $comp = '10/2026'): Folha
    {
        $dados = (new ExtratoParser)->lerTexto(file_get_contents(__DIR__.'/../Fixtures/extrato_ficticio.txt'));

        return Folha::create(['competencia' => $comp, 'empresa_codigo' => '1', 'empresa_nome' => 'EMPRESA EXEMPLO', 'cnpj' => $dados['cnpj'],
            'calculo' => $calculo, 'dados' => $dados, 'origem' => 'teste', 'importado_por' => 'rh']);
    }

    private function conectar(): void
    {
        Configuracao::salvar([
            'contaazul_client_id' => 'cliente-teste', 'contaazul_client_secret' => 'segredo-teste',
            'contaazul_access_token' => 'token-velho', 'contaazul_refresh_token' => 'refresh-1', 'contaazul_token_expira' => '0',
            'contaazul_fornecedor_id' => 'forn-1', 'contaazul_fornecedor_nome' => 'Folha de Pagamento', 'contaazul_conta_financeira' => 'conta-1',
            'contaazul_categoria_folha' => 'cat-salarios', 'contaazul_categoria_adiantamento' => 'cat-adiant',
        ]);
    }

    public function test_vencimento_mensal_e_o_quinto_dia_util_e_adiantamento_o_dia_20(): void
    {
        // nov/2026: 01 domingo, 02 feriado (Finados) -> 3,4,5,6 e sábado 7 = 5º dia útil
        $this->assertSame('07/11/2026', $this->folha()->vencimentoPagamento());
        $this->assertSame('20/11/2026', $this->folha('Adiantamento', '11/2026')->vencimentoPagamento());
    }

    public function test_conectar_redireciona_para_o_login_com_state_e_retorno_troca_o_codigo(): void
    {
        Configuracao::salvar(['contaazul_client_id' => 'cliente-teste', 'contaazul_client_secret' => 'segredo-teste']);
        $r = $this->get('/contaazul/conectar');
        $url = $r->headers->get('Location');
        $this->assertStringStartsWith('https://login.contaazul.com/#/oauth/authorize?', $url);
        parse_str(parse_url(substr($url, strpos($url, '?')), PHP_URL_QUERY) ?? substr($url, strpos($url, '?') + 1), $q);
        $this->assertSame('cliente-teste', $q['client_id']);
        $this->assertSame('openid profile aws.cognito.signin.user.admin', $q['scope']);

        Http::fake(['api-v2.contaazul.com/oauth/token' => Http::response(['access_token' => 'at', 'refresh_token' => 'rt', 'expires_in' => 3600])]);
        $this->get('/contaazul/retorno?code=abc&state=errado')->assertSessionHasErrors('contaazul');
        $this->get('/contaazul/conectar');
        $this->get('/contaazul/retorno?code=abc&state='.session('contaazul_state'))->assertRedirect('/contaazul');

        $this->assertSame('rt', Configuracao::ler('contaazul_refresh_token'));
        $this->assertNotSame('rt', Configuracao::find('contaazul_refresh_token')->valor);   // criptografado no banco
        Http::assertSent(fn (Request $req) => $req->url() === self::API.'/oauth/token'
            && $req->hasHeader('Authorization', 'Basic '.base64_encode('cliente-teste:segredo-teste'))
            && $req['grant_type'] === 'authorization_code' && $req['code'] === 'abc');
    }

    public function test_enviar_folha_cria_conta_a_pagar_com_o_liquido_total_uma_unica_vez(): void
    {
        $this->conectar();
        $folha = $this->folha();
        Http::fake([
            self::API.'/oauth/token' => Http::response(['access_token' => 'token-novo', 'refresh_token' => 'refresh-2', 'expires_in' => 3600]),
            self::API.'/v1/financeiro/contas-a-pagar' => Http::response(['id' => 'evento-123'], 201),
        ]);

        $this->post("/folha/{$folha->id}/contaazul", ['vencimento' => '2026-11-07'])->assertRedirect("/folha/{$folha->id}");
        $this->post("/folha/{$folha->id}/contaazul", ['vencimento' => '2026-11-07'])->assertSessionHasErrors('contaazul');

        $folha->refresh();
        $this->assertSame('evento-123', $folha->contaazul_id);
        $this->assertSame('refresh-2', Configuracao::ler('contaazul_refresh_token'));   // refresh_token novo guardado
        $total = round($folha->dados['total_liquido'], 2);
        Http::assertSentCount(2);   // 1 renovação de token + 1 conta a pagar (o 2º clique não chama a API)
        Http::assertSent(function (Request $req) use ($total, $folha) {
            if ($req->url() !== self::API.'/v1/financeiro/contas-a-pagar') {
                return false;
            }
            $b = $req->data();

            return $req->hasHeader('Authorization', 'Bearer token-novo')
                && $b['valor'] == $total && $b['id_fornecedor'] === 'forn-1' && $b['conta_financeira'] === 'conta-1'
                && $b['data_competencia'] === '2026-10-01' && $b['referencia_externa'] === "portal-folha-{$folha->id}"
                && $b['rateio'] == [['id_categoria' => 'cat-salarios', 'valor' => $total]]
                && $b['condicao_pagamento']['parcelas'][0]['data_vencimento'] === '2026-11-07'
                && $b['condicao_pagamento']['parcelas'][0]['detalhe_valor']['valor_bruto'] == $total;
        });
        $this->get("/folha/{$folha->id}")->assertSee('Lançado')->assertSee('07/11/2026');
    }

    public function test_adiantamento_usa_a_categoria_do_adiantamento(): void
    {
        $this->conectar();
        Configuracao::salvar(['contaazul_token_expira' => (string) (time() + 3000)]);
        $folha = $this->folha('Adiantamento', '11/2026');
        Http::fake([self::API.'/v1/financeiro/contas-a-pagar' => Http::response(['id' => 'ev-ad'], 201)]);

        $this->post("/folha/{$folha->id}/contaazul", ['vencimento' => '2026-11-20']);

        Http::assertSentCount(1);   // token ainda válido: não renova
        Http::assertSent(fn (Request $req) => $req['rateio'][0]['id_categoria'] === 'cat-adiant'
            && str_starts_with($req['descricao'], 'Adiantamento 11/2026'));
    }

    public function test_erro_da_api_aparece_e_nao_marca_como_lancado(): void
    {
        $this->conectar();
        $folha = $this->folha();
        Http::fake([
            self::API.'/oauth/token' => Http::response(['access_token' => 'x', 'refresh_token' => 'y', 'expires_in' => 3600]),
            self::API.'/v1/financeiro/contas-a-pagar' => Http::response(['error' => 'Categoria não encontrada'], 400),
        ]);

        $this->post("/folha/{$folha->id}/contaazul", ['vencimento' => '2026-11-07'])->assertSessionHasErrors('contaazul');
        $this->assertNull($folha->fresh()->contaazul_id);
        $this->assertStringContainsString('Categoria não encontrada', session('errors')->first('contaazul'));
    }

    public function test_tela_da_folha_pede_configuracao_quando_nao_conectado(): void
    {
        $folha = $this->folha();
        $this->get("/folha/{$folha->id}")->assertSee('Conta Azul ainda não conectado');
        $this->get('/contaazul')->assertOk()->assertSee('Client ID')->assertDontSee('segredo');
    }
}
