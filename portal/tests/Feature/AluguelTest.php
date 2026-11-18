<?php

namespace Tests\Feature;

use App\Models\Aluguel;
use App\Models\Configuracao;
use App\Models\TarefaAgente;
use App\Models\User;
use App\Services\IrrfAluguel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AluguelTest extends TestCase
{
    use RefreshDatabase;

    public function test_calculo_reproduz_a_nota_de_setembro_e_o_email_de_outubro(): void
    {
        // nota real 09/2026 na Escrita Fiscal: base 7.254,5x -> IRRF 1.073,57 (com a redução de 2026)
        $set = IrrfAluguel::calcular([8060.63], '09/2026');
        $this->assertSame(1073.57, $set['irrf']);
        $this->assertSame(12.71, $set['reducao']);
        // a Domínio chega no IRRF da imobiliária com a base ajustada
        $this->assertSame(1118.60, IrrfAluguel::irrfDaBase(IrrfAluguel::baseParaIrrf(1118.60, '10/2026', 7372.13), '10/2026'));
        // se a imobiliária não aplicar a redução (1.086,28), o portal acha a base que dá esse valor
        $base = IrrfAluguel::baseParaIrrf(1086.28, '09/2026', 7254.57);
        $this->assertSame(1086.28, IrrfAluguel::irrfDaBase($base, '09/2026'));
        // antes de 2026 não há redução
        $this->assertSame(0.0, IrrfAluguel::calcular([8060.63], '09/2025')['reducao']);
    }

    public function test_tela_salva_calcula_e_envia_ao_robo_sem_reinf(): void
    {
        $this->actingAs(User::factory()->create(['name' => 'rh']));
        $itens = [
            ['tipo' => 'aluguel', 'descricao' => 'Aluguel OUTUBRO sem IGPM - 01 a 16/10', 'valor' => '4.160,34'],
            ['tipo' => 'aluguel', 'descricao' => 'Aluguel OUTUBRO com IGPM 3,35% - 17 a 31/10', 'valor' => '4.030,91'],
            ['tipo' => 'seguro', 'descricao' => 'Seguro fiança 37/47', 'valor' => '922,51'],
            ['tipo' => 'iptu', 'descricao' => 'IPTU 316', 'valor' => '219,03'],
            ['tipo' => 'iptu', 'descricao' => 'IPTU 326', 'valor' => '282,90'],
        ];
        $form = ['competencia' => '10/2026', 'data_nota' => '2026-10-31', 'vencimento' => '2026-11-05', 'itens' => $itens, 'irrf_imobiliaria' => '1.118,60', 'valor_boleto' => '8.497,08'];

        $this->post('/alugueis', $form + ['acao' => 'salvar'])->assertRedirect();
        $a = Aluguel::firstWhere('competencia', '10/2026');
        $this->assertSame(8191.25, $a->calculo['valor_produtos']);
        $this->assertSame(922.51, $a->calculo['seguro']);
        $this->assertSame(9113.76, $a->calculo['valor_contabil']);
        $this->assertSame(1118.60, $a->calculo['irrf_conferido']);
        $this->assertSame(8497.08, $a->calculo['boleto']);            // saldo cobrado no e-mail
        $this->assertSame(8497.09, $a->calculo['boleto_calculado']);  // a Schier usou 1.118,6058 sem arredondar
        $this->get('/alugueis?competencia=10/2026')->assertOk()->assertSee('Base de cálculo a digitar')->assertSee('8.497,08');

        // sem o CPF do locador não dá para gerar o arquivo da Domínio
        $this->post('/alugueis', $form + ['acao' => 'lancar'])->assertSessionHasErrors('aluguel');
        $this->assertSame(0, TarefaAgente::count());
        Configuracao::salvar(['aluguel_fornecedor_inscricao' => '111.444.777-35', 'esocial_cnpj' => '11.222.333/0001-81']);

        $this->post('/alugueis', $form + ['acao' => 'lancar'])->assertRedirect();
        $t = TarefaAgente::firstWhere('tipo', 'lancar_nota_aluguel');
        $this->assertSame('aluguel_102026.txt', $t->payload['arquivo']);
        $this->assertSame(
            "|0000|11222333000181|
"
            ."|1000|42|11144477735||602|1949||102026|||31/10/2026|31/10/2026|9113,76||||T||||320806||||||922,51||||||||||||8191,25|
"
            ."|1020|63|0,00|7372,12|27,50|1118,60|0,00|0,00|0,00|0,00|9113,76|320806|
",
            base64_decode($t->payload['txt_base64']));
        $this->assertFalse($t->payload['transmitir_reinf']);
        $this->assertSame('102026', $t->payload['nota']['documento']);
        $this->assertSame('31/10/2026', $t->payload['nota']['emissao']);
        $this->assertSame('8.191,25', $t->payload['nota']['valor_produtos']);
        $this->assertSame('na_fila', $a->fresh()->status);

        // já enviado: não deixa alterar nem enviar de novo
        $this->post('/alugueis', $form + ['acao' => 'lancar'])->assertSessionHasErrors('aluguel');
        $this->assertSame(1, TarefaAgente::where('tipo', 'lancar_nota_aluguel')->count());
    }
}
