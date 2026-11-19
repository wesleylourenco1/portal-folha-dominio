<?php

namespace Tests\Feature;

use App\Models\Aluguel;
use App\Models\TarefaAgente;
use App\Models\User;
use App\Services\ReinfStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReinfTest extends TestCase
{
    use RefreshDatabase;

    /** Texto como o smalot/pdfparser extrai o "Relatório de Consulta do Envio dos Arquivos da EFD-Reinf" (fictício). */
    private const ENVIADO = "Competência:10/2026 a 10/2026\n"
        ."1 - EMPRESA EXEMPLO LTDA\t10/202611.222.333/0001-81\tOficial20/11/2026 - 10:20InvalidadoInclusãoR-1000 - Informações do Contribuinte\n"
        ."1 - EMPRESA EXEMPLO LTDA\t10/202611.222.333/0001-81\tOficial20/11/2026 - 10:201111-10-4010-2610-1111SucessoInclusãoR-4010 - Pagamentos/créditos a beneficiário pessoa física\n";

    private const FECHADO = self::ENVIADO
        ."11 - EMPRESA EXEMPLO LTDA\t10/202611.222.333/0001-81\tOficial20/11/2026 - 10:212222-10-4099-2610-2222SucessoFechamentoR-4099 - Fechamento/reabertura dos eventos da série R-4000\n";

    private function aluguel(string $status = 'lancado'): Aluguel
    {
        return Aluguel::create(['competencia' => '10/2026', 'data_nota' => '31/10/2026', 'itens' => [['tipo' => 'aluguel', 'descricao' => 'Aluguel', 'valor' => 8191.25]],
            'irrf_imobiliaria' => 1118.60, 'perc_administracao' => 10, 'status' => $status]);
    }

    private function robo(Aluguel $a, ?string $texto): void
    {
        $t = TarefaAgente::where('tipo', 'transmitir_reinf')->latest('id')->first();
        $t->update(['status' => 'concluida']);
        ReinfStatus::retorno($a->fresh(), $t, true, 'OK', [], $texto === null ? [] : ReinfStatus::lerTexto($texto));
    }

    private function ultima(): TarefaAgente
    {
        return TarefaAgente::where('tipo', 'transmitir_reinf')->latest('id')->first();
    }

    public function test_le_o_relatorio_da_consulta(): void
    {
        $e = ReinfStatus::lerTexto(self::FECHADO);
        $this->assertCount(3, $e);
        $this->assertSame(['competencia' => '10/2026', 'enviado_em' => '20/11/2026 - 10:20', 'recibo' => '1111-10-4010-2610-1111',
            'situacao' => 'Sucesso', 'tipo' => 'Inclusão', 'evento' => 'R-4010', 'descricao' => 'Pagamentos/créditos a beneficiário pessoa física'], $e[1]);
        $this->assertSame('Invalidado', $e[0]['situacao']);   // R-1000 antigo não atrapalha
        $this->assertSame('transmitido', ReinfStatus::situacao($e, '10/2026')['status']);
        $this->assertSame(['R-4099'], ReinfStatus::situacao(ReinfStatus::lerTexto(self::ENVIADO), '10/2026')['faltam']);
        $this->assertSame('nada', ReinfStatus::situacao([], '10/2026')['status']);
    }

    public function test_envia_entrega_completa_reconfere_e_conclui_sem_dctfweb(): void
    {
        $this->actingAs(User::factory()->create(['name' => 'rh']));
        $a = $this->aluguel();
        $this->get('/alugueis?competencia=10/2026')->assertOk()->assertSee('Transmitir EFD-Reinf');

        $this->post('/alugueis/reinf', ['competencia' => '10/2026', 'acao' => 'enviar'])->assertRedirect();
        $this->assertSame('enviar', $this->ultima()->payload['etapa']);
        $this->assertSame('Entrega completa', $this->ultima()->payload['opcao']);
        $this->assertFalse($this->ultima()->payload['transmitir_dctfweb']);
        $this->post('/alugueis/reinf', ['competencia' => '10/2026', 'acao' => 'enviar'])->assertSessionHasErrors('aluguel');

        // R-4010 com sucesso, fechamento ainda não: reconfere sozinho
        $this->robo($a, self::ENVIADO);
        $this->assertSame('aguardando', $a->fresh()->reinf_status);
        $this->assertSame('conferir', $this->ultima()->payload['etapa']);
        $this->assertSame(ReinfStatus::ESPERA_SEGUNDOS, $this->ultima()->payload['esperar_segundos']);

        $this->robo($a, self::FECHADO);
        $a->refresh();
        $this->assertSame('transmitido', $a->reinf_status);
        $this->assertStringContainsString('2222-10-4099-2610-2222', $a->reinf_mensagem);
        $this->assertSame(2, TarefaAgente::count());
        $this->get('/alugueis?competencia=10/2026')->assertOk()->assertSee('Transmitida e conferida')->assertSee('1111-10-4010-2610-1111')
            ->assertDontSee('>Transmitir EFD-Reinf<', false);
        $this->post('/alugueis/reinf', ['competencia' => '10/2026', 'acao' => 'enviar'])->assertSessionHasErrors('aluguel');
    }

    public function test_so_conferir_nota_nao_lancada_e_erro(): void
    {
        $this->actingAs(User::factory()->create(['name' => 'rh']));
        $a = $this->aluguel('rascunho');
        $this->post('/alugueis/reinf', ['competencia' => '10/2026', 'acao' => 'enviar'])->assertSessionHasErrors('aluguel');
        $this->assertSame(0, TarefaAgente::count());

        $this->post('/alugueis/reinf', ['competencia' => '10/2026', 'acao' => 'conferir'])->assertRedirect();
        $this->robo($a, null);                                        // "Sem dados para emitir"
        $this->assertSame('pendente', $a->fresh()->reinf_status);
        $this->assertSame(1, TarefaAgente::count());

        $a->update(['status' => 'lancado', 'reinf_status' => null]);
        $this->post('/alugueis/reinf', ['competencia' => '10/2026', 'acao' => 'enviar']);
        $this->robo($a, str_replace('1111SucessoInclusão', '1111ErroInclusão', self::ENVIADO));
        $this->assertSame('erro', $a->fresh()->reinf_status);
        $this->assertStringContainsString('R-4010 Erro', $a->fresh()->reinf_mensagem);
    }
}
