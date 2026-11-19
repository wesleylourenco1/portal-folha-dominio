<?php

namespace Tests\Feature;

use App\Models\Admissao;
use App\Models\Configuracao;
use App\Models\Funcionario;
use App\Models\Lancamento;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PortalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['name' => 'rh']));
    }

    public function test_segredos_ficam_criptografados_e_nao_voltam_para_a_tela(): void
    {
        $this->post('/configuracoes', ['ia_provedor' => 'openai', 'ia_chave' => 'sk-segredo-de-teste', 'acao' => 'salvar'])->assertOk();

        $bruto = DB::table('configuracoes')->where('chave', 'ia_chave')->value('valor');
        $this->assertStringNotContainsString('sk-segredo', $bruto);
        $this->assertSame('sk-segredo-de-teste', Configuracao::ler('ia_chave'));
        $this->get('/configuracoes')->assertDontSee('sk-segredo-de-teste')->assertSee('chave salva');

        // campo em branco mantém a chave atual
        $this->post('/configuracoes', ['ia_provedor' => 'openai', 'ia_chave' => '', 'acao' => 'salvar']);
        $this->assertSame('sk-segredo-de-teste', Configuracao::ler('ia_chave'));
    }

    public function test_lancamentos_salvar_validar_fechar_e_reabrir(): void
    {
        Funcionario::create(['codigo' => '7', 'nome' => 'MARIA EXEMPLO', 'ativo' => true, 'desconto_vr' => 165]);
        Funcionario::create(['codigo' => '9', 'nome' => 'JOAO FICTICIO', 'ativo' => true]);

        $this->get('/lancamentos?comp=11/2026')->assertOk()->assertSee('MARIA EXEMPLO')->assertSee('165,00');

        $this->post('/lancamentos', ['comp' => '11/2026', 'acao' => 'salvar', 'v' => [
            '7' => ['horas_extras' => '18h14', 'faltas' => '12, 5', 'comissao' => '782,35'],
            '9' => ['horas_extras' => 'xx'],
        ]])->assertOk()->assertSee('Use horas:minutos');

        $maria = Lancamento::where(['competencia' => '11/2026', 'funcionario_codigo' => '7'])->first();
        $this->assertSame(['horas_extras' => '18:14', 'faltas' => '05/11, 12/11', 'comissao' => '782,35'], $maria->dados);
        $this->assertNull(Lancamento::where('funcionario_codigo', '9')->first(), 'linha com erro não é gravada');

        $this->post('/lancamentos', ['comp' => '11/2026', 'acao' => 'fechar', 'v' => ['9' => ['horas_extras' => '2:00']]])
            ->assertSee('competência fechada');
        $this->post('/lancamentos', ['comp' => '11/2026', 'acao' => 'salvar', 'v' => ['9' => ['horas_extras' => '3:00']]])
            ->assertSee('Reabra para alterar');
        $this->post('/lancamentos', ['comp' => '11/2026', 'acao' => 'reabrir'])->assertRedirect();
        $this->assertDatabaseHas('lancamento_competencias', ['competencia' => '11/2026', 'status' => 'aberta']);
    }

    public function test_dados_do_funcionario_editaveis_e_pix_criptografado(): void
    {
        $f = Funcionario::create(['codigo' => '7', 'nome' => 'MARIA EXEMPLO', 'ativo' => true]);

        $this->post("/funcionarios/{$f->id}", ['local' => 'LOJA CENTRO', 'vale_transporte' => '200,00', 'comissionado' => '1', 'chave_pix' => 'maria@exemplo.com'])
            ->assertRedirect('/funcionarios');

        $f->refresh();
        $this->assertSame('LOJA CENTRO', $f->local);
        $this->assertSame(200.0, $f->vale_transporte);
        $this->assertTrue($f->comissionado);
        $this->assertSame('maria@exemplo.com', $f->chave_pix);
        $this->assertStringNotContainsString('maria@exemplo.com', DB::table('funcionarios')->value('chave_pix'));
    }

    public function test_admissao_de_teste_nao_vai_para_a_dominio(): void
    {
        Queue::fake();
        Storage::fake('local');
        $this->post('/admissoes', ['teste' => '1', 'data_admissao' => '10/11/2026', 'cargo' => 'ATENDENTE', 'salario' => '2.000,00',
            'documentos' => [UploadedFile::fake()->image('rg.jpg')]])->assertRedirect();
        $adm = Admissao::first();
        $adm->update(['status' => 'revisar']);
        $this->get("/admissoes/{$adm->id}")->assertSee('TESTE')->assertSee('desativado no teste');

        $dados = ['nome' => 'MARIA EXEMPLO', 'cpf' => '529.982.247-25', 'data_nascimento' => '01/01/1990',
            'data_admissao' => '10/11/2026', 'cargo' => 'ATENDENTE', 'salario' => '2.000,00', 'acao' => 'aprovar'];
        $this->post("/admissoes/{$adm->id}", $dados)->assertRedirect();

        $this->assertSame('revisar', $adm->fresh()->status);
        $this->assertSame('MARIA EXEMPLO', $adm->fresh()->dados['nome']);
        $this->assertDatabaseCount('tarefas_agente', 0);
    }

    public function test_pedido_de_adiantamento_vai_para_a_fila_uma_vez(): void
    {
        $this->post('/folha/adiantamento', ['acao' => 'gerar', 'competencia' => '11/2026'])->assertRedirect('/folha');
        $this->post('/folha/adiantamento', ['acao' => 'gerar', 'competencia' => '11/2026']);

        $this->assertDatabaseCount('tarefas_agente', 1);
        $this->get('/folha')->assertSee('Na fila do robô')->assertSee('offline');
    }

    public function test_robo_aparece_online_depois_de_consultar_o_portal(): void
    {
        Cache::put('agente_visto_em', time(), 60);
        $this->get('/folha')->assertSee('online')->assertDontSee('offline');
    }
}
