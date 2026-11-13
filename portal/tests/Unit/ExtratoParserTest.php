<?php

namespace Tests\Unit;

use App\Services\ExtratoParser;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class ExtratoParserTest extends TestCase
{
    private function extrato(): array
    {
        return (new ExtratoParser)->lerTexto(file_get_contents(__DIR__.'/../Fixtures/extrato_ficticio.txt'));
    }

    public function test_le_cabecalho_e_totais(): void
    {
        $e = $this->extrato();

        $this->assertSame('10/2026', $e['competencia']);
        $this->assertSame('Folha Mensal', $e['calculo']);
        $this->assertSame('1', $e['empresa_codigo']);
        $this->assertSame('11.222.333/0001-81', $e['cnpj']);
        $this->assertCount(2, $e['funcionarios']);
        $this->assertEqualsWithDelta(2206.73, $e['total_liquido'], 0.001);
        $this->assertEqualsWithDelta(321.45, $e['total_inss'], 0.001);
        $this->assertEqualsWithDelta(355.45, $e['total_fgts'], 0.001);
        $this->assertTrue($e['confere'], 'soma dos líquidos deve bater com o Líquido Geral');
    }

    public function test_le_funcionario_e_rubricas(): void
    {
        $maria = $this->extrato()['funcionarios'][0];

        $this->assertSame('7', $maria['codigo']);
        $this->assertSame('MARIA EXEMPLO DA SILVA', $maria['nome']);
        $this->assertSame('ATENDENTE', $maria['cargo']);
        $this->assertSame('521140', $maria['cbo']);
        $this->assertEqualsWithDelta(1106.73, $maria['liquido'], 0.001);
        $this->assertCount(7, $maria['rubricas']);

        $somaProventos = array_sum(array_map(fn ($r) => $r['tipo'] === 'provento' ? $r['valor'] : 0, $maria['rubricas']));
        $somaDescontos = array_sum(array_map(fn ($r) => $r['tipo'] === 'desconto' ? $r['valor'] : 0, $maria['rubricas']));
        $this->assertEqualsWithDelta($maria['proventos'], $somaProventos, 0.001);
        $this->assertEqualsWithDelta($maria['descontos'], $somaDescontos, 0.001);

        $horas = collect($maria['rubricas'])->firstWhere('codigo', '202');
        $this->assertSame('10:30', $horas['referencia']);
    }

    public function test_identifica_consignado_e_ignora_estorno(): void
    {
        [$maria, $joao] = $this->extrato()['funcionarios'];

        $this->assertEqualsWithDelta(200.00, $maria['consignado'], 0.001);
        $this->assertEqualsWithDelta(0.0, $joao['consignado'], 0.001, 'estorno de empréstimo não é desconto de consignado');
    }

    public function test_rejeita_pdf_que_nao_e_extrato(): void
    {
        $this->expectException(RuntimeException::class);
        (new ExtratoParser)->lerTexto('RELATÓRIO QUALQUER');
    }
}
