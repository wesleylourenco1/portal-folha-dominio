<?php

namespace Tests\Feature;

use App\Models\Admissao;
use App\Models\User;
use App\Support\Br;
use App\Support\CamposAdmissao;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CamposAdmissaoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Storage::fake('local');
        $this->actingAs(User::factory()->create());
    }

    public function test_data_do_calendario_e_guardada_em_formato_brasileiro(): void
    {
        $this->assertSame('05/03/2026', CamposAdmissao::normalizar('data_admissao', '2026-03-05'));
        $this->assertSame('2026-03-05', CamposAdmissao::paraInput('data_admissao', '05/03/2026'));
        $this->assertSame('45', CamposAdmissao::normalizar('experiencia_dias', ' 45 '));
    }

    public function test_experiencia_somada_nao_passa_de_90_dias(): void
    {
        $this->assertSame([], Br::validarExperiencia(['experiencia_dias' => '45', 'experiencia_prorrogacao_dias' => '45']));
        $this->assertArrayHasKey('experiencia_prorrogacao_dias', Br::validarExperiencia(['experiencia_dias' => '45', 'experiencia_prorrogacao_dias' => '46']));
        $this->assertArrayHasKey('experiencia_dias', Br::validarExperiencia(['experiencia_dias' => 'abc']));
    }

    public function test_nova_admissao_com_experiencia_acima_de_90_e_recusada(): void
    {
        $this->post('/admissoes', [
            'documentos' => [UploadedFile::fake()->create('rg.pdf', 5, 'application/pdf')],
            'data_admissao' => '2026-11-16', 'experiencia_dias' => '60', 'experiencia_prorrogacao_dias' => '45',
        ])->assertSessionHasErrors('experiencia_prorrogacao_dias');
        $this->assertDatabaseCount('admissoes', 0);
    }

    public function test_nova_admissao_guarda_data_e_raca_cor(): void
    {
        $this->post('/admissoes', [
            'documentos' => [UploadedFile::fake()->create('rg.pdf', 5, 'application/pdf')],
            'data_admissao' => '2026-11-16', 'raca_cor' => 'Parda', 'experiencia_dias' => '45', 'experiencia_prorrogacao_dias' => '45',
        ])->assertRedirect();
        $dados = Admissao::first()->dados;
        $this->assertSame('16/11/2026', $dados['data_admissao']);
        $this->assertSame('Parda', $dados['raca_cor']);
    }

    public function test_tela_de_revisao_mostra_calendario_lista_e_numeros(): void
    {
        $adm = Admissao::create(['status' => 'revisar', 'dados' => ['data_admissao' => '16/11/2026', 'raca_cor' => 'Parda'], 'extracao' => []]);
        $this->get("/admissoes/{$adm->id}")->assertOk()
            ->assertSee('type="date" name="data_admissao"', false)
            ->assertSee('value="2026-11-16"', false)
            ->assertSee('<select name="raca_cor"', false)
            ->assertSee('type="number" name="experiencia_dias"', false);
    }

    public function test_contato_vem_so_do_rh_e_a_leitura_nao_apaga(): void
    {
        $this->post('/admissoes', [
            'documentos' => [UploadedFile::fake()->create('rg.pdf', 5, 'application/pdf')],
            'email' => 'rh.digitou@exemplo.com', 'telefone' => '(11) 98888-7777',
        ])->assertRedirect();
        $adm = Admissao::first();
        $this->assertSame('rh.digitou@exemplo.com', $adm->dados['email']);

        $falso = new class extends \App\Services\ExtratorDocumentos
        {
            public function __construct() {}

            public function extrair(array $arquivos): array
            {
                return ['email' => 'lido@documento.com', 'telefone' => '(11) 99999-0000', 'nome' => 'Fulano de Teste'];
            }
        };
        (new \App\Jobs\ExtrairDocumentos($adm->id))->handle($falso);

        $dados = $adm->fresh()->dados;
        $this->assertSame('rh.digitou@exemplo.com', $dados['email']);   // a leitura não mexe no contato
        $this->assertSame('(11) 98888-7777', $dados['telefone']);
        $this->assertSame('Fulano de Teste', $dados['nome']);
        $this->assertNotContains('telefone', \App\Support\CamposAdmissao::chaves('doc'));
        $this->assertNotContains('email', \App\Support\CamposAdmissao::chaves('doc'));
        $this->get('/admissoes/nova')->assertSee('name="email"', false)->assertSee('name="telefone"', false);
    }

    public function test_aprovar_com_experiencia_acima_de_90_nao_envia(): void
    {
        $adm = Admissao::create(['status' => 'revisar', 'dados' => [], 'extracao' => []]);
        $this->post("/admissoes/{$adm->id}", [
            'acao' => 'aprovar', 'nome' => 'Fulano de Teste', 'cpf' => '529.982.247-25', 'data_nascimento' => '1990-01-01',
            'data_admissao' => '2026-11-16', 'cargo' => 'Auxiliar', 'salario' => '2000,00',
            'experiencia_dias' => '45', 'experiencia_prorrogacao_dias' => '50',
        ]);
        $this->assertSame('revisar', $adm->fresh()->status);
        $this->assertDatabaseCount('tarefas_agente', 0);
    }
}
