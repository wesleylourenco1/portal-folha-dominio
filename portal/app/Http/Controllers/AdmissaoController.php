<?php

namespace App\Http\Controllers;

use App\Jobs\ExtrairDocumentos;
use App\Models\Admissao;
use App\Models\Funcao;
use App\Models\TarefaAgente;
use App\Support\Br;
use App\Support\CamposAdmissao;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class AdmissaoController extends Controller
{
    public function index()
    {
        return view('admissoes.index', ['admissoes' => Admissao::latest('id')->get()]);
    }

    public function create()
    {
        return view('admissoes.nova', ['funcoes' => Funcao::orderBy('nome')->get(), 'campos' => CamposAdmissao::CAMPOS]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'documentos' => 'required|array|min:1',
            'documentos.*' => 'file|max:51200|mimes:pdf,jpg,jpeg,png,webp,zip',
        ]);
        try {
            $arquivos = \App\Support\Documentos::expandir($request->file('documentos'));
        } catch (\RuntimeException $e) {
            return back()->withErrors(['documentos' => $e->getMessage()])->withInput();
        }
        $dados = [];
        foreach (CamposAdmissao::chaves('rh') as $c) {
            $dados[$c] = trim((string) $request->input($c, ''));
        }
        $dados['_teste'] = $request->boolean('teste') ? '1' : '';
        $adm = Admissao::create(['status' => 'extraindo', 'dados' => $dados, 'extracao' => [], 'criado_por' => $request->user()->id]);
        foreach ($arquivos as ['nome' => $nome, 'conteudo' => $conteudo, 'mime' => $mime]) {
            $caminho = "admissoes/{$adm->id}/".\Illuminate\Support\Str::uuid().'.'.strtolower(pathinfo($nome, PATHINFO_EXTENSION));
            Storage::disk('local')->put($caminho, $conteudo);
            $adm->documentos()->create(['nome_original' => $nome, 'caminho' => $caminho, 'mime' => $mime]);
        }
        $adm->registrar("Criada por {$request->user()->name} com ".count($arquivos).' documento(s)');
        ExtrairDocumentos::dispatch($adm->id);

        return redirect()->route('admissoes.show', $adm);
    }

    public function show(Admissao $admissao)
    {
        $editavel = in_array($admissao->status, Admissao::EDITAVEIS, true);

        return view('admissoes.revisar', [
            'adm' => $admissao->load('documentos'),
            'campos' => CamposAdmissao::CAMPOS,
            'erros' => $editavel ? Br::validarAdmissao($admissao->dados ?? []) : [],
            'incertos' => $admissao->extracao['campos_incertos'] ?? [],
            'editavel' => $editavel,
        ]);
    }

    public function update(Request $request, Admissao $admissao)
    {
        abort_unless(in_array($admissao->status, Admissao::EDITAVEIS, true), 409, 'Esta admissão não pode ser editada agora.');
        $dados = [];
        foreach (CamposAdmissao::chaves() as $c) {
            $dados[$c] = trim((string) $request->input($c, ''));
        }
        $dados['_teste'] = $admissao->dados['_teste'] ?? '';
        $usuario = $request->user()->name;

        switch ($request->input('acao')) {
            case 'aprovar':
                if ($dados['_teste']) {
                    $admissao->update(['dados' => $dados, 'mensagem' => 'Admissão de TESTE: dados salvos, nada foi enviado para a Domínio.']);
                    break;
                }
                if ($erros = Br::validarAdmissao($dados)) {
                    $admissao->update(['dados' => $dados, 'mensagem' => 'Corrija os campos destacados antes de aprovar.']);
                    break;
                }
                $admissao->update(['dados' => $dados, 'status' => 'aprovado', 'mensagem' => null]);
                TarefaAgente::enfileirar('cadastrar_admissao', ['admissao_id' => $admissao->id], $usuario);
                $admissao->registrar("Aprovada por {$usuario}");
                break;
            case 'reextrair':
                $admissao->update(['dados' => $dados, 'status' => 'extraindo', 'mensagem' => null]);
                ExtrairDocumentos::dispatch($admissao->id);
                break;
            default:
                $admissao->update(['dados' => $dados, 'status' => 'revisar', 'mensagem' => 'Alterações salvas.']);
                $admissao->registrar("Editada por {$usuario}");
        }

        return redirect()->route('admissoes.show', $admissao);
    }

    public function documento(Admissao $admissao, int $documento)
    {
        $doc = $admissao->documentos()->findOrFail($documento);

        return Storage::disk('local')->response($doc->caminho, $doc->nome_original);
    }

    /** LGPD: apaga os documentos depois do cadastro concluído. */
    public function apagarDocumentos(Request $request, Admissao $admissao)
    {
        abort_unless(in_array($admissao->status, ['aguardando_esocial', 'concluido'], true), 409);
        Storage::disk('local')->deleteDirectory("admissoes/{$admissao->id}");
        $admissao->documentos()->delete();
        $admissao->registrar("Documentos apagados por {$request->user()->name} (LGPD)");

        return redirect()->route('admissoes.show', $admissao);
    }
}
