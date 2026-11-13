<?php

namespace Tests\Feature;

use App\Models\Admissao;
use App\Models\Configuracao;
use App\Models\Funcionario;
use App\Models\TarefaAgente;
use App\Services\ExtratoParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AgenteApiTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->token = str_repeat('t', 64);
        config(['services.agente.token' => $this->token]);
        Storage::fake('local');
    }

    private function api(): static
    {
        return $this->withHeader('Authorization', 'Bearer '.$this->token);
    }

    public function test_sem_token_ou_token_errado_e_recusado(): void
    {
        $this->getJson('/api/agente/tarefas/proxima')->assertUnauthorized();
        $this->withHeader('Authorization', 'Bearer errado')->getJson('/api/agente/tarefas/proxima')->assertUnauthorized();
    }

    public function test_api_fechada_sem_token_configurado(): void
    {
        config(['services.agente.token' => '']);
        $this->withHeader('Authorization', 'Bearer ')->getJson('/api/agente/tarefas/proxima')->assertUnauthorized();
    }

    public function test_fila_vazia_responde_204(): void
    {
        $this->api()->getJson('/api/agente/tarefas/proxima')->assertNoContent();
    }

    public function test_fluxo_de_cadastro_de_admissao(): void
    {
        $adm = Admissao::create(['status' => 'aprovado', 'dados' => ['nome' => 'MARIA EXEMPLO', 'cpf' => '529.982.247-25']]);
        TarefaAgente::enfileirar('cadastrar_admissao', ['admissao_id' => $adm->id]);

        $tarefa = $this->api()->getJson('/api/agente/tarefas/proxima')->assertOk()
            ->assertJsonPath('tipo', 'cadastrar_admissao')
            ->assertJsonPath('payload.dados.nome', 'MARIA EXEMPLO')->json();
        $this->assertSame('cadastrando', $adm->fresh()->status);

        // a mesma tarefa não é entregue duas vezes
        $this->api()->getJson('/api/agente/tarefas/proxima')->assertNoContent();

        $this->api()->postJson("/api/agente/tarefas/{$tarefa['id']}/concluir", ['sucesso' => true])->assertOk();
        $this->assertSame('aguardando_esocial', $adm->fresh()->status);
    }

    public function test_erro_no_cadastro_volta_para_o_rh(): void
    {
        $adm = Admissao::create(['status' => 'aprovado', 'dados' => ['nome' => 'JOAO']]);
        $t = TarefaAgente::enfileirar('cadastrar_admissao', ['admissao_id' => $adm->id]);
        $this->api()->getJson('/api/agente/tarefas/proxima');

        $this->api()->postJson("/api/agente/tarefas/{$t->id}/concluir", ['sucesso' => false, 'mensagem' => 'CPF já cadastrado'])->assertOk();
        $this->assertSame('erro_cadastro', $adm->fresh()->status);
        $this->assertStringContainsString('CPF já cadastrado', $adm->fresh()->mensagem);
    }

    public function test_envio_de_folha_importa_e_atualiza_funcionarios_e_funcoes(): void
    {
        $extrato = (new ExtratoParser)->lerTexto(file_get_contents(__DIR__.'/../Fixtures/extrato_ficticio.txt'));
        $this->mock(ExtratoParser::class)->shouldReceive('lerArquivo')->andReturn($extrato);

        $this->api()->post('/api/agente/folhas', ['extrato' => UploadedFile::fake()->create('ExtratoMensal-102026.pdf', 10, 'application/pdf')])
            ->assertOk()->assertJsonPath('funcionarios', 2)->assertJsonPath('total_liquido', 2206.73);

        $this->assertSame(2, Funcionario::ativos()->count());
        $this->assertDatabaseHas('funcoes', ['codigo' => '3', 'nome' => 'ATENDENTE', 'cbo' => '521140']);
    }

    public function test_adiantamento_automatico_e_enfileirado_no_dia(): void
    {
        $this->travelTo(now()->setDay(16));
        Configuracao::salvar(['adiantamento_automatico' => 'sim', 'adiantamento_dia_gerar' => '15']);

        $this->api()->getJson('/api/agente/tarefas/proxima')->assertOk()->assertJsonPath('tipo', 'gerar_adiantamento')
            ->assertJsonPath('payload.competencia', now()->format('m/Y'));
        // não cria de novo no mesmo mês
        $this->api()->getJson('/api/agente/tarefas/proxima')->assertNoContent();
    }
}
