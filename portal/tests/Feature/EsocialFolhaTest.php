<?php

namespace Tests\Feature;

use App\Models\Folha;
use App\Models\TarefaAgente;
use App\Models\User;
use App\Services\ContaAzul;
use App\Services\EsocialStatusFolha;
use App\Services\ExtratoParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EsocialFolhaTest extends TestCase
{
    use RefreshDatabase;

    /** Texto como o smalot/pdfparser extrai a "Relação de Status dos Eventos Periódicos" (dados fictícios). */
    private const TEXTO = "1 - EMPRESA EXEMPLO LTDAEmpresa:\nCNPJ:\nCompetência:10/2026\nCálculo:\n11.222.333/0001-81\nFolha Mensal\n"
        ."RELAÇÃO DE STATUS DOS EVENTOS PERIÓDICOS\nPagamentoRemuneraçãoCompetênciaCPFNomeMatricula eSocial\n"
        ."34\t10/2026111.444.777-35FULANO SEM FOLHA34\n"
        ."20\tValidadoValidado10/2026222.333.444-05BELTRANO DA SILVA20\n"
        ."5\tEm ProcessamentoValidado10/2026333.444.555-50CICLANO DE SOUZA5\n";

    private const NADA = "Competência:10/2026\n20\t10/2026222.333.444-05BELTRANO DA SILVA20\n5\t10/2026333.444.555-50CICLANO DE SOUZA5\n";

    private function folha(string $calculo = 'Folha Mensal', string $comp = '10/2026'): Folha
    {
        $dados = (new ExtratoParser)->lerTexto(file_get_contents(__DIR__.'/../Fixtures/extrato_ficticio.txt'));

        return Folha::create(['competencia' => $comp, 'empresa_codigo' => '1', 'empresa_nome' => 'EMPRESA EXEMPLO', 'cnpj' => $dados['cnpj'],
            'calculo' => $calculo, 'dados' => $dados, 'origem' => 'teste', 'importado_por' => 'rh']);
    }

    /** Simula o robô concluindo a última tarefa da fila com o relatório e o resultado do Analítico INSS. */
    private function robo(Folha $f, string $texto, bool $fechado = false): TarefaAgente
    {
        $t = TarefaAgente::where('tipo', 'transmitir_esocial_folha')->latest('id')->first();
        $t->update(['status' => 'concluida']);
        EsocialStatusFolha::retorno($f->fresh(), $t, true, 'OK', ['fechamento_validado' => $fechado], EsocialStatusFolha::lerTexto($texto));

        return $t;
    }

    private function ultima(): TarefaAgente
    {
        return TarefaAgente::where('tipo', 'transmitir_esocial_folha')->latest('id')->first();
    }

    public function test_le_o_relatorio_de_status_da_dominio(): void
    {
        $l = EsocialStatusFolha::lerTexto(self::TEXTO);
        $this->assertSame('10/2026', $l['competencia']);
        $this->assertCount(3, $l['empregados']);
        $this->assertSame(['codigo' => '20', 'nome' => 'BELTRANO DA SILVA', 'cpf' => '222.333.444-05', 'remuneracao' => 'Validado', 'pagamento' => 'Validado'], $l['empregados'][1]);
        $this->assertSame('', $l['empregados'][0]['remuneracao']);
        $this->assertSame('Em Processamento', $l['empregados'][2]['pagamento']);
        $this->assertSame(['status' => 'aguardando', 'total' => 2, 'validados' => 1, 'com_erro' => 0], EsocialStatusFolha::situacao($l['empregados']));
        $this->assertSame('nada', EsocialStatusFolha::situacao(EsocialStatusFolha::lerTexto(self::NADA)['empregados'])['status']);
        $rej = str_replace('Em ProcessamentoValidado', 'RejeitadoValidado', self::TEXTO);
        $this->assertSame('erro', EsocialStatusFolha::situacao(EsocialStatusFolha::lerTexto($rej)['empregados'])['status']);
    }

    public function test_fluxo_completo_remuneracao_conferencia_fechamento_sem_dctfweb(): void
    {
        $this->actingAs(User::factory()->create(['name' => 'rh']));
        $f = $this->folha();
        $this->get("/folha/{$f->id}")->assertOk()->assertSee('Transmitir eSocial');

        // 1. pedido: robô envia S-1200/S-1210
        $this->post("/folha/{$f->id}/esocial", ['acao' => 'transmitir'])->assertRedirect();
        $this->assertSame('remuneracao', $this->ultima()->payload['etapa']);
        $this->assertFalse($this->ultima()->payload['transmitir_dctfweb']);
        $this->post("/folha/{$f->id}/esocial", ['acao' => 'transmitir'])->assertSessionHasErrors('esocial');   // não duplica

        // 2. um ainda em processamento: o portal agenda nova conferência com espera
        $this->robo($f, self::TEXTO);
        $this->assertSame('aguardando', $f->fresh()->esocial_status);
        $this->assertSame('conferir', $this->ultima()->payload['etapa']);
        $this->assertSame(EsocialStatusFolha::ESPERA_SEGUNDOS, $this->ultima()->payload['esperar_segundos']);

        // 3. todos validados: o portal manda o fechamento sozinho
        $ok = str_replace('Em ProcessamentoValidado', 'ValidadoValidado', self::TEXTO);
        $this->robo($f, $ok);
        $this->assertSame('fechamento', $this->ultima()->payload['etapa']);
        $this->assertSame('na_fila', $f->fresh()->esocial_status);

        // 4. fechamento enviado, ainda não validado: confere de novo
        $this->robo($f, $ok, false);
        $this->assertSame('fechando', $f->fresh()->esocial_status);
        $this->assertSame('conferir', $this->ultima()->payload['etapa']);
        $this->assertTrue($this->ultima()->payload['fechamento_enviado']);

        // 5. Analítico INSS emitido = fechamento validado
        $this->robo($f, $ok, true);
        $f->refresh();
        $this->assertSame('transmitido', $f->esocial_status);
        $this->assertNotNull($f->esocial_fechado_em);
        $this->assertSame(4, TarefaAgente::count());                 // nada mais na fila
        $this->get("/folha/{$f->id}")->assertOk()->assertSee('Transmitido e conferido')->assertSee('BELTRANO DA SILVA')->assertSee('DCTFWeb');
        $this->post("/folha/{$f->id}/esocial", ['acao' => 'transmitir'])->assertSessionHasErrors('esocial');
        $this->get('/folha')->assertOk()->assertSee('Transmitido e conferido');   // status em cada competência
    }

    public function test_lista_mostra_nao_transmitido_e_link_dos_liquidos_vai_para_o_conta_azul(): void
    {
        $this->actingAs(User::factory()->create(['name' => 'rh']));
        $f = $this->folha();
        $this->get('/folha')->assertOk()->assertSee('Não transmitido');
        $obs = ContaAzul::payload($f, '06/11/2026')['observacao'];
        $this->assertStringContainsString("/folha/{$f->id}/pdf/liquidos", $obs);
        $this->assertLessThan(1000, mb_strlen($obs));
    }

    public function test_para_de_conferir_sozinho_depois_do_limite_e_rejeicao_nao_fecha(): void
    {
        $this->actingAs(User::factory()->create(['name' => 'rh']));
        $f = $this->folha();
        $this->post("/folha/{$f->id}/esocial", ['acao' => 'transmitir']);
        for ($i = 0; $i < EsocialStatusFolha::MAX_TENTATIVAS + 1; $i++) {
            $this->robo($f, self::TEXTO);
        }
        $this->assertSame('pendente', $f->fresh()->esocial_status);
        $this->assertSame(EsocialStatusFolha::MAX_TENTATIVAS + 1, TarefaAgente::count());

        $g = $this->folha('Folha Mensal', '09/2026');
        $this->post("/folha/{$g->id}/esocial", ['acao' => 'transmitir']);
        $this->robo($g, str_replace('Em ProcessamentoValidado', 'RejeitadoValidado', self::TEXTO));
        $this->assertSame('erro', $g->fresh()->esocial_status);
        $this->assertNotSame('fechamento', $this->ultima()->payload['etapa']);
    }

    public function test_so_conferir_nao_envia_nada_e_adiantamento_nao_tem_esocial(): void
    {
        $this->actingAs(User::factory()->create(['name' => 'rh']));
        $ad = $this->folha('Adiantamento');
        $this->get("/folha/{$ad->id}")->assertOk()->assertDontSee('Transmitir eSocial');
        $this->post("/folha/{$ad->id}/esocial", ['acao' => 'transmitir'])->assertSessionHasErrors('esocial');

        $f = $this->folha();
        $this->post("/folha/{$f->id}/esocial", ['acao' => 'conferir'])->assertRedirect();
        $this->assertSame('conferir', $this->ultima()->payload['etapa']);
        $this->robo($f, self::NADA);
        $this->assertSame('pendente', $f->fresh()->esocial_status);
        $this->assertSame(1, TarefaAgente::count());                 // conferir não dispara envio

        // remunerações validadas antes (ex.: enviadas à mão): o botão manda só o fechamento
        $f->update(['esocial_status' => 'validado']);
        $this->get("/folha/{$f->id}")->assertSee('Enviar fechamento (S-1299)');
        $this->post("/folha/{$f->id}/esocial", ['acao' => 'transmitir']);
        $this->assertSame('fechamento', $this->ultima()->payload['etapa']);

        // erro do robô aparece na folha
        config(['services.agente.token' => $tok = str_repeat('t', 64)]);
        $this->withHeader('Authorization', 'Bearer '.$tok)
            ->postJson('/api/agente/tarefas/'.$this->ultima()->id.'/concluir', ['sucesso' => false, 'mensagem' => 'A Domínio perguntou: X'])->assertOk();
        $this->assertSame('erro', $f->fresh()->esocial_status);
        $this->assertStringContainsString('A Domínio perguntou', $f->fresh()->esocial_mensagem);
    }
}
