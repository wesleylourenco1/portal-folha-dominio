<?php

namespace Tests\Feature;

use App\Models\Admissao;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

class ZipDocumentosTest extends TestCase
{
    use RefreshDatabase;

    private function zip(array $entradas): UploadedFile
    {
        $caminho = tempnam(sys_get_temp_dir(), 'zip');
        $z = new ZipArchive;
        $z->open($caminho, ZipArchive::OVERWRITE);
        foreach ($entradas as $nome => $conteudo) {
            $z->addFromString($nome, $conteudo);
        }
        $z->close();

        return new UploadedFile($caminho, 'documentos.zip', 'application/zip', null, true);
    }

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Storage::fake('local');
        $this->actingAs(User::factory()->create());
    }

    public function test_zip_e_aberto_e_so_documentos_suportados_entram(): void
    {
        $zip = $this->zip([
            'rg.pdf' => '%PDF-1.4 rg',
            'pessoais/cpf.jpg' => 'jpg-cpf',
            'pessoais/ctps.png' => 'png-ctps',
            'leia-me.txt' => 'ignorar',
            '__MACOSX/._rg.pdf' => 'lixo',
            '../../fora.pdf' => '%PDF-1.4 tentativa de zip slip',
        ]);

        $this->post('/admissoes', ['teste' => '1', 'documentos' => [$zip, UploadedFile::fake()->create('titulo.pdf', 5, 'application/pdf')]])
            ->assertRedirect();

        $adm = Admissao::with('documentos')->first();
        $nomes = $adm->documentos->pluck('nome_original')->sort()->values()->all();
        $this->assertSame(['cpf.jpg', 'ctps.png', 'fora.pdf', 'rg.pdf', 'titulo.pdf'], $nomes);
        foreach ($adm->documentos as $doc) {   // tudo gravado dentro da pasta da admissão, com nome gerado pelo portal
            $this->assertStringStartsWith("admissoes/{$adm->id}/", $doc->caminho);
            $this->assertStringNotContainsString('..', $doc->caminho);
            Storage::disk('local')->assertExists($doc->caminho);
        }
    }

    public function test_zip_sem_documentos_e_recusado(): void
    {
        $this->post('/admissoes', ['documentos' => [$this->zip(['nota.txt' => 'x'])]])
            ->assertSessionHasErrors('documentos');
        $this->assertDatabaseCount('admissoes', 0);
    }
}
