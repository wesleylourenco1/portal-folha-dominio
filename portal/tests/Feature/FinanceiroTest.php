<?php

namespace Tests\Feature;

use App\Models\Configuracao;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FinanceiroTest extends TestCase
{
    use RefreshDatabase;

    private function conectar(): void
    {
        Configuracao::salvar(['contaazul_client_id' => 'cliente-teste', 'contaazul_client_secret' => 'segredo-teste',
            'contaazul_access_token' => 'token', 'contaazul_refresh_token' => 'refresh-1', 'contaazul_token_expira' => (string) (time() + 3600)]);
    }

    private function item(string $venc, string $forn, float $total, float $pago, string $status): array
    {
        return ['id' => uniqid(), 'descricao' => "Conta {$forn}", 'data_vencimento' => $venc, 'status' => 'X', 'status_traduzido' => $status,
            'total' => $total, 'pago' => $pago, 'nao_pago' => $total - $pago, 'categorias' => [['id' => 'c1', 'nome' => 'Combustível']],
            'fornecedor' => ['id' => 'f', 'nome' => $forn]];
    }

    public function test_mostra_contas_do_dia_e_atrasadas_em_aberto(): void
    {
        $this->actingAs(User::factory()->create(['name' => 'rh']));
        $this->conectar();
        $posto = $this->item('2026-11-23', 'POSTO KFA', 1500.00, 0, 'EM_ABERTO');
        Http::fake(function (Request $r) use ($posto) {
            parse_str((string) parse_url($r->url(), PHP_URL_QUERY), $q);
            if (str_contains($r->url(), '/v1/conta-financeira')) {
                return Http::response(['itens' => [['id' => 'cc-1', 'nome' => 'CARTÃO   SICREDI', 'tipo' => 'CARTAO_CREDITO'],
                    ['id' => 'cc-2', 'nome' => 'Conta corrente', 'tipo' => 'CONTA_CORRENTE']]]);
            }
            $this->assertStringContainsString('/v1/financeiro/eventos-financeiros/contas-a-pagar/buscar', $r->url());
            if (($q['ids_contas_financeiras'] ?? null) === 'cc-1') {      // o que foi lançado no cartão
                return Http::response(['itens_totais' => 1, 'itens' => $q['data_vencimento_de'] === '2026-11-23' ? [$posto] : []]);
            }
            $itens = $q['data_vencimento_de'] === '2026-11-23'
                ? [$posto, $this->item('2026-11-23', 'ENERGIA', 300.50, 300.50, 'RECEBIDO')]
                : [$this->item('2026-11-10', 'BANCO', 800.00, 0, 'ATRASADO'), $this->item('2026-11-05', 'QUITADO', 100, 100, 'RECEBIDO'),
                    $this->item('2026-11-01', 'ACORDO', 50, 0, 'RENEGOCIADO')];

            return Http::response(['itens_totais' => count($itens), 'itens' => $itens]);
        });

        $this->get('/financeiro?dia=2026-11-23')->assertOk()
            ->assertSee('Contas a pagar — 23/11/2026')
            ->assertSee('POSTO KFA')->assertSee('1.800,50')          // total do dia
            ->assertSee('1.500,00')                                   // em aberto do dia
            ->assertSee('BANCO')->assertSee('800,00')                 // atrasada em aberto
            ->assertDontSee('QUITADO')->assertDontSee('ACORDO')       // paga e renegociada não aparecem como atrasadas
            ->assertSee('CARTÃO SICREDI');                    // forma de pagamento
        Http::assertSentCount(5);                                     // cartões + dia + atrasadas (geral e por cartão)

        $this->get('/financeiro?dia=2026-11-23&forma=outros')->assertOk()->assertSee('ENERGIA')->assertDontSee('POSTO KFA');
        $this->get('/financeiro?dia=2026-11-23&forma=cartao')->assertOk()->assertSee('POSTO KFA')->assertDontSee('ENERGIA');
    }

    public function test_sem_conexao_ou_com_erro_avisa(): void
    {
        $this->actingAs(User::factory()->create(['name' => 'rh']));
        $this->get('/financeiro')->assertOk()->assertSee('Conta Azul não conectado');

        $this->conectar();
        Http::fake(['*' => Http::response(['message' => 'falhou'], 500)]);
        $this->get('/financeiro?dia=2026-11-24')->assertOk()->assertSee('Não consegui consultar o Conta Azul');
    }
}
