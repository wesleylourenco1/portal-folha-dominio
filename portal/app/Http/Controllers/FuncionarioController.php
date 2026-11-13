<?php

namespace App\Http\Controllers;

use App\Models\Funcionario;
use App\Support\Br;
use Illuminate\Http\Request;

class FuncionarioController extends Controller
{
    public function index(Request $request)
    {
        $todos = $request->boolean('todos');
        $lista = ($todos ? Funcionario::query() : Funcionario::ativos())->orderBy('nome')->get();

        return view('funcionarios.index', ['lista' => $lista, 'todos' => $todos]);
    }

    /** Dados do escritório que não vêm da Domínio (local, VT, VR, PIX, comissionado). */
    public function update(Request $request, Funcionario $funcionario)
    {
        $dinheiro = fn ($v) => ($v === null || $v === '') ? null : Br::valor($v);
        $funcionario->update([
            'local' => $request->input('local') ?: null,
            'vale_transporte' => $dinheiro($request->input('vale_transporte')),
            'vr_mes' => $dinheiro($request->input('vr_mes')),
            'desconto_vr' => $dinheiro($request->input('desconto_vr')),
            'comissionado' => $request->boolean('comissionado'),
            'chave_pix' => $request->input('chave_pix') ?: null,
        ]);

        return redirect()->route('funcionarios.index')->with('msg', "Dados de {$funcionario->nome} salvos.");
    }
}
