<?php

namespace Tests\Unit;

use App\Support\Br;
use PHPUnit\Framework\TestCase;

class BrTest extends TestCase
{
    public function test_cpf(): void
    {
        $this->assertTrue(Br::cpfValido('529.982.247-25'));
        $this->assertFalse(Br::cpfValido('529.982.247-24'));
        $this->assertFalse(Br::cpfValido('111.111.111-11'));
        $this->assertFalse(Br::cpfValido('123'));
    }

    public function test_pis(): void
    {
        $this->assertTrue(Br::pisValido('120.54933.65-3'));
        $this->assertFalse(Br::pisValido('120.54933.65-4'));
        $this->assertFalse(Br::pisValido('000.00000.00-0'));
    }

    public function test_moeda_e_valor(): void
    {
        $this->assertSame('1.234,56', Br::moeda(1234.56));
        $this->assertEqualsWithDelta(1234.56, Br::valor('R$ 1.234,56'), 0.001);
        $this->assertEqualsWithDelta(1234.56, Br::valor('1234.56'), 0.001);
    }

    public function test_validar_admissao(): void
    {
        $erros = Br::validarAdmissao(['nome' => 'MARIA', 'cpf' => '529.982.247-24', 'data_nascimento' => '31/02/1990']);

        $this->assertArrayHasKey('cpf', $erros);
        $this->assertArrayHasKey('data_nascimento', $erros);
        $this->assertSame('Campo obrigatório', $erros['cargo']);
    }
}
