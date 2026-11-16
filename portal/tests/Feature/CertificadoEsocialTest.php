<?php

namespace Tests\Feature;

use App\Models\Configuracao;
use App\Models\User;
use App\Services\CertificadoEsocial;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CertificadoEsocialTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'token-de-teste-com-mais-de-32-caracteres-123';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['services.agente.token' => self::TOKEN]);
        Configuracao::salvar(['esocial_cnpj' => '11.222.333/0001-81']);
        $this->actingAs(User::factory()->create(['name' => 'rh']));
    }

    /** Gera um .pfx de teste (A1 autoassinado) com o CNPJ no nome, como os e-CNPJ. */
    private function pfx(string $cnpj = '11222333000181', string $senha = 'senha-teste'): UploadedFile
    {
        $cfg = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA, 'digest_alg' => 'sha256'];
        if (getenv('OPENSSL_CONF') === false && is_file($c = dirname(PHP_BINARY).'/extras/ssl/openssl.cnf')) {
            $cfg['config'] = $c;
        }
        $chave = openssl_pkey_new($cfg);
        $csr = openssl_csr_new(['commonName' => "EMPRESA TESTE LTDA:$cnpj"], $chave, $cfg);
        $cert = openssl_csr_sign($csr, null, $chave, 365, $cfg);
        openssl_pkcs12_export($cert, $saida, $chave, $senha);
        $caminho = tempnam(sys_get_temp_dir(), 'pfx');
        file_put_contents($caminho, $saida);

        return new UploadedFile($caminho, 'empresa.pfx', 'application/x-pkcs12', null, true);
    }

    public function test_envia_certificado_que_fica_criptografado_e_so_o_robo_recebe(): void
    {
        $this->post('/configuracoes/certificado', ['certificado' => $this->pfx(), 'senha_certificado' => 'senha-teste'])
            ->assertOk()->assertSee('Certificado salvo')->assertSee('EMPRESA TESTE LTDA:11222333000181')->assertDontSee('senha-teste');

        $guardado = Storage::disk('local')->get(CertificadoEsocial::ARQUIVO);
        $this->assertStringNotContainsString('EMPRESA TESTE', base64_decode($guardado) ?: '');   // não é o .pfx em claro
        $this->assertNotSame('senha-teste', Configuracao::find('esocial_certificado_senha')->valor);   // senha criptografada

        $this->get('/api/agente/certificado')->assertUnauthorized();
        $r = $this->withHeader('Authorization', 'Bearer '.self::TOKEN)->getJson('/api/agente/certificado')->assertOk();
        $this->assertSame('senha-teste', $r->json('senha'));
        $this->assertTrue(openssl_pkcs12_read(base64_decode($r->json('pfx_base64')), $c, 'senha-teste'));
    }

    public function test_senha_errada_e_cnpj_de_outra_empresa_sao_recusados(): void
    {
        $this->post('/configuracoes/certificado', ['certificado' => $this->pfx(), 'senha_certificado' => 'errada'])
            ->assertSee('senha incorreta');
        $this->post('/configuracoes/certificado', ['certificado' => $this->pfx('99888777000166'), 'senha_certificado' => 'senha-teste'])
            ->assertSee('diferente da empresa');
        $this->assertFalse(app(CertificadoEsocial::class)->existe());
    }

    public function test_remover_e_robo_sem_certificado(): void
    {
        $this->post('/configuracoes/certificado', ['certificado' => $this->pfx(), 'senha_certificado' => 'senha-teste']);
        $this->post('/configuracoes/certificado/remover')->assertSee('removido');
        Storage::disk('local')->assertMissing(CertificadoEsocial::ARQUIVO);
        $this->withHeader('Authorization', 'Bearer '.self::TOKEN)->getJson('/api/agente/certificado')->assertNotFound();
    }
}
