<?php

namespace Tests\Feature;

use App\Models\Admissao;
use App\Models\Configuracao;
use App\Models\Funcionario;
use App\Models\TarefaAgente;
use App\Models\User;
use App\Services\EsocialConsultas;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class EsocialTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'token-de-teste-com-mais-de-32-caracteres-123';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.agente.token' => self::TOKEN]);
        Configuracao::salvar(['esocial_ativo' => 'sim', 'esocial_cnpj' => '11.222.333/0001-81', 'esocial_consultas_dia' => '3']);
        Carbon::setTestNow(Carbon::create(2026, 11, 16, 9, 0, 0));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function func(string $codigo, string $admissao, string $cpf, array $extra = []): Funcionario
    {
        return Funcionario::create(['codigo' => $codigo, 'nome' => "FUNC $codigo", 'cpf' => $cpf, 'admissao' => $admissao, 'ativo' => true] + $extra);
    }

    private function agente()
    {
        return $this->withHeader('Authorization', 'Bearer '.self::TOKEN);
    }

    public function test_planeja_admissao_recente_primeiro_e_respeita_o_saldo(): void
    {
        $antigo = $this->func('1', '10/01/2020', '111.444.777-35');
        $novo = $this->func('2', '03/11/2026', '529.982.247-25');
        $this->func('3', '05/05/2021', '123.456.789-09');
        $this->func('4', '05/05/2022', '987.654.321-00');

        $c = app(EsocialConsultas::class)->planejar(3);

        $this->assertCount(3, $c);
        $this->assertSame([$novo->id, 'admissao'], [$c[0]['funcionario_id'], $c[0]['motivo']]);
        $this->assertSame('2026-10-14T00:00:00', $c[0]['dt_ini']);   // 20 dias antes da admissão
        $this->assertSame('2026-11-13T23:59:59', $c[0]['dt_fim']);   // até 10 dias depois
        $this->assertSame('52998224725', $c[0]['cpf']);
        $this->assertSame('rotina', $c[1]['motivo']);
        $this->assertSame('2026-10-17T07:00:00', $c[1]['dt_ini']);   // últimos 30 dias até 2 h atrás
        $this->assertSame('2026-11-16T07:00:00', $c[1]['dt_fim']);
        $this->assertContains($antigo->id, array_column($c, 'funcionario_id'));
    }

    public function test_robo_agenda_uma_vez_por_dia_e_nunca_entre_1_e_7(): void
    {
        $this->func('2', '03/11/2026', '529.982.247-25');
        Carbon::setTestNow(Carbon::create(2026, 11, 5, 9));
        $this->agente()->getJson('/api/agente/tarefas/proxima');
        $this->assertDatabaseCount('tarefas_agente', 0);

        Carbon::setTestNow(Carbon::create(2026, 11, 16, 9));
        $r = $this->agente()->getJson('/api/agente/tarefas/proxima')->assertOk();
        $this->assertSame('consultar_esocial', $r->json('tipo'));
        $this->assertSame('11222333000181', $r->json('payload.cnpj'));
        $this->agente()->postJson("/api/agente/tarefas/{$r->json('id')}/concluir", ['sucesso' => true, 'resultado' => ['itens' => [], 'parado' => null]]);
        $this->agente()->getJson('/api/agente/tarefas/proxima')->assertNoContent();   // não agenda de novo no mesmo dia
        $this->assertSame(1, TarefaAgente::where('tipo', 'consultar_esocial')->count());
    }

    public function test_resultado_marca_admissao_aceita_e_conclui_a_admissao(): void
    {
        $f = $this->func('2', '03/11/2026', '529.982.247-25');
        $adm = Admissao::create(['status' => 'aguardando_esocial', 'dados' => ['cpf' => '529.982.247-25', 'nome' => 'FUNC 2'], 'extracao' => []]);
        $t = TarefaAgente::enfileirar('consultar_esocial', ['consultas' => []]);

        $this->agente()->postJson("/api/agente/tarefas/{$t->id}/concluir", ['sucesso' => true, 'resultado' => [
            'itens' => [['funcionario_id' => $f->id, 'motivo' => 'admissao', 'codigo' => '201', 'descricao' => 'Consulta realizada com sucesso.',
                'total' => 1, 'ultimo' => '2026-11-02T15:00:00', 'recibos' => ['1.2.0000000000000000001']]],
            'parado' => null, 'validade_certificado' => '10/03/2027',
        ]])->assertOk();

        $f->refresh();
        $this->assertTrue($f->esocial_admissao_ok);
        $this->assertSame('1.2.0000000000000000001', $f->esocial_recibo_admissao);
        $this->assertSame('concluido', $adm->fresh()->status);
        $this->assertSame('10/03/2027', Configuracao::ler('esocial_validade_certificado'));

        $this->actingAs(User::factory()->create());
        $this->get('/funcionarios')->assertSee('Admissão aceita')->assertSee('1.2.0000000000000000001');
    }

    public function test_sem_eventos_e_limite_do_dia(): void
    {
        $f = $this->func('1', '10/01/2020', '111.444.777-35');
        app(EsocialConsultas::class)->aplicar(['itens' => [['funcionario_id' => $f->id, 'motivo' => 'rotina', 'codigo' => '406',
            'descricao' => 'Não foram encontrados registros']], 'parado' => '405 limite esgotado']);
        $this->assertSame('sem_eventos', $f->fresh()->esocial_situacao);
        $this->assertStringContainsString('Parou por hoje', Configuracao::ler('esocial_status'));

        $this->actingAs(User::factory()->create());
        Configuracao::salvar(['esocial_consultas_dia' => '1']);
        $this->post("/funcionarios/{$f->id}/esocial")->assertSessionHas('msg', fn ($m) => str_contains($m, 'enviada ao robô'));
        $this->post("/funcionarios/{$f->id}/esocial")->assertSessionHas('msg', fn ($m) => str_contains($m, 'limite'));
        $this->assertSame(1, TarefaAgente::where('tipo', 'consultar_esocial')->count());
    }
}
