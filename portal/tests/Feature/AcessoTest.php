<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AcessoTest extends TestCase
{
    use RefreshDatabase;

    public function test_telas_exigem_login(): void
    {
        foreach (['/', '/folha', '/lancamentos', '/funcionarios', '/funcoes', '/configuracoes'] as $url) {
            $this->get($url)->assertRedirect('/login');
        }
    }

    public function test_login_com_senha_correta(): void
    {
        $u = User::factory()->create(['password' => bcrypt('senha-forte-123')]);

        $this->post('/login', ['email' => $u->email, 'password' => 'senha-forte-123'])->assertRedirect('/');
        $this->assertAuthenticatedAs($u);
    }

    public function test_login_com_senha_errada_e_bloqueio(): void
    {
        $u = User::factory()->create(['password' => bcrypt('senha-forte-123')]);

        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['email' => $u->email, 'password' => 'errada'])->assertSessionHasErrors('email');
        }
        $this->post('/login', ['email' => $u->email, 'password' => 'senha-forte-123'])->assertSessionHasErrors('email');
        $this->assertStringContainsString('Muitas tentativas', session('errors')->first('email'));
        $this->assertGuest();
    }

    public function test_telas_abrem_logado(): void
    {
        $this->actingAs(User::factory()->create());
        foreach (['/', '/admissoes/nova', '/folha', '/lancamentos', '/funcionarios', '/funcoes', '/configuracoes'] as $url) {
            $this->get($url)->assertOk();
        }
    }
}
