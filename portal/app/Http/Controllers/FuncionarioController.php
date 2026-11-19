<?php

namespace App\Http\Controllers;

use App\Models\Configuracao;
use App\Models\Funcionario;
use App\Services\EsocialConsultas;
use App\Support\Br;
use Illuminate\Http\Request;

class FuncionarioController extends Controller
{
    public function index(Request $request)
    {
        $todos = $request->boolean('todos');
        $lista = ($todos ? Funcionario::query() : Funcionario::ativos())->orderBy('nome')->get();

        $es = app(EsocialConsultas::class);

        return view('funcionarios.index', ['lista' => $lista, 'todos' => $todos, 'esocialAtivo' => $es->ativo(),
            'esocialSaldo' => max(0, $es->porDia() - $es->usadasHoje()), 'esocialStatus' => Configuracao::ler('esocial_status')]);
    }

    /** Consulta este funcionário no eSocial agora (pelo robô, com o certificado da empresa). */
    public function esocial(Request $request, Funcionario $funcionario)
    {
        $msg = app(EsocialConsultas::class)->consultarAgora($funcionario, $request->user()->name);

        return redirect()->route('funcionarios.index')->with('msg', $msg);
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
