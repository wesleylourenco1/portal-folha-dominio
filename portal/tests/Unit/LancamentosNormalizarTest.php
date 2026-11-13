<?php

namespace Tests\Unit;

use App\Services\Lancamentos;
use PHPUnit\Framework\TestCase;

class LancamentosNormalizarTest extends TestCase
{
    public function test_horas(): void
    {
        $l = new Lancamentos;
        $this->assertSame(['18:14', null], $l->normalizar('horas_extras', '18:14'));
        $this->assertSame(['18:05', null], $l->normalizar('horas_extras', '18h5'));
        $this->assertSame(['18:30', null], $l->normalizar('horas_extras', '18,5'));
        $this->assertNotNull($l->normalizar('horas_extras', 'abc')[1]);
    }

    public function test_dias_de_falta(): void
    {
        $l = new Lancamentos;
        $this->assertSame(['05/11, 12/11', null], $l->normalizar('faltas', '12, 5', '11/2026'));
        $this->assertSame(['05/11', null], $l->normalizar('faltas', '05/11/2026', '11/2026'));
        $this->assertStringContainsString('não existe', $l->normalizar('faltas', '31', '11/2026')[1]);
        $this->assertStringContainsString('não é da competência', $l->normalizar('faltas', '3/10', '11/2026')[1]);
        $this->assertSame(2, Lancamentos::contarDatas('05/11, 12/11'));
    }

    public function test_moeda(): void
    {
        $l = new Lancamentos;
        $this->assertSame(['782,35', null], $l->normalizar('comissao', '782,35'));
        $this->assertSame(['1.234,50', null], $l->normalizar('comissao', '1.234,5'));
        $this->assertNotNull($l->normalizar('comissao', '-3')[1]);
        $this->assertNotNull($l->normalizar('comissao', 'dez reais')[1]);
    }
}
