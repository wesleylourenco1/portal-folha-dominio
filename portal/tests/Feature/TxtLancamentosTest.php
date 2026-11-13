<?php

namespace Tests\Feature;

use App\Models\Configuracao;
use App\Models\Lancamento;
use App\Models\User;
use App\Services\DominioTxtLancamentos;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TxtLancamentosTest extends TestCase
{
    use RefreshDatabase;

    private function linhas(string $comp = '11/2026'): array
    {
        $r = app(DominioTxtLancamentos::class)->gerar($comp);

        return array_values(array_filter(explode("\r\n", $r['conteudo'])));
    }

    public function test_gera_registros_no_leiaute_da_dominio(): void
    {
        Lancamento::create(['competencia' => '11/2026', 'funcionario_codigo' => '7', 'dados' => [
            'horas_extras' => '18:14', 'faltas' => '05/11, 12/11', 'comissao' => '782,35', 'desconto_vr' => '165,00',
        ]]);

        $l = $this->linhas();

        // registro 10: "10" + empregado(10) + AAAAMM + rubrica(9) + processo(2) + valor(9) + empresa(10) = 48 posições
        $this->assertSame('10'.'0000000007'.'202611'.'000000202'.'11'.'000001814'.'0000000001', $l[0]);
        $this->assertSame(48, strlen($l[0]));
        // faltas: 2 dias (200) + um registro 11 por data
        $this->assertSame('10000000000720261100000879211000000200'.'0000000001', $l[1]);
        $this->assertSame('11202611051', $l[2]);
        $this->assertSame('11202611121', $l[3]);
        $this->assertStringContainsString('000000037'.'11'.'000078235', $l[4]);
        $this->assertStringContainsString('000000201'.'11'.'000016500', $l[5]);
        $this->assertCount(6, $l);
    }

    public function test_respeita_configuracoes(): void
    {
        Configuracao::salvar(['dominio_empresa_codigo' => '25', 'dominio_tipo_processo' => '41',
            'dominio_formato_horas' => 'centesimal', 'dominio_rubrica_horas_extras' => '150']);
        Lancamento::create(['competencia' => '11/2026', 'funcionario_codigo' => '9', 'dados' => ['horas_extras' => '18:15']]);

        $this->assertSame('10'.'0000000009'.'202611'.'000000150'.'41'.'000001825'.'0000000025', $this->linhas()[0]);
    }

    public function test_falta_vr_sem_rubrica_gera_aviso_e_fica_de_fora(): void
    {
        Lancamento::create(['competencia' => '11/2026', 'funcionario_codigo' => '9', 'dados' => ['faltas_vr' => '03/11']]);

        $r = app(DominioTxtLancamentos::class)->gerar('11/2026');
        $this->assertSame(0, $r['linhas']);
        $this->assertNotEmpty($r['avisos']);
    }

    public function test_download_pela_tela(): void
    {
        $this->actingAs(User::factory()->create());
        Lancamento::create(['competencia' => '11/2026', 'funcionario_codigo' => '7', 'dados' => ['comissao' => '100,00']]);

        $this->get('/lancamentos/txt?comp=11/2026')->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename="lancamentos_dominio_112026.txt"');
        $this->get('/lancamentos/txt?comp=12/2026')->assertOk()->assertSee('Nenhum lançamento');
    }
}
