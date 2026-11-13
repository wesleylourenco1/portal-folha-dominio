<?php

namespace App\Http\Controllers;

use App\Models\Funcao;
use App\Support\Br;
use Illuminate\Http\Request;

class FuncaoController extends Controller
{
    public function index(Request $request)
    {
        return view('funcoes.index', [
            'funcoes' => Funcao::orderBy('nome')->get(),
            'atual' => $request->query('editar') ? Funcao::find($request->query('editar')) : null,
        ]);
    }

    public function salvar(Request $request)
    {
        $dados = $request->validate([
            'codigo' => 'nullable|string|max:20',
            'nome' => 'required|string|max:120',
            'cbo' => 'nullable|string|max:10',
            'salario' => 'nullable|string|max:20',
            'tipo_salario' => 'nullable|in:Mensal,Horista,Diarista',
            'horas_mes' => 'nullable|integer|min:1|max:440',
            'horario' => 'nullable|string|max:120',
            'departamento' => 'nullable|string|max:60',
        ]);
        $dados['salario'] = $dados['salario'] ? Br::valor($dados['salario']) : null;
        $dados['horas_mes'] ??= 220;
        $dados['tipo_salario'] ??= 'Mensal';

        $request->input('id')
            ? Funcao::findOrFail($request->input('id'))->update($dados)
            : Funcao::create($dados);

        return redirect()->route('funcoes.index');
    }

    public function excluir(Funcao $funcao)
    {
        $funcao->delete();

        return redirect()->route('funcoes.index');
    }
}
