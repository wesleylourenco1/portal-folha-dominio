<?php

namespace App\Http\Controllers;

use App\Models\Configuracao;
use App\Models\Folha;
use App\Services\ContaAzul;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ContaAzulController extends Controller
{
    public function index(ContaAzul $ca)
    {
        $listas = ['categorias' => [], 'contas' => [], 'fornecedores' => []];
        $erro = null;
        if ($ca->conectado()) {
            try {
                $listas = ['categorias' => $ca->categoriasDespesa(), 'contas' => $ca->contasFinanceiras(), 'fornecedores' => $ca->fornecedores()];
            } catch (\Throwable $e) {
                $erro = $e->getMessage();
            }
        }

        return view('contaazul', $listas + [
            'v' => collect(ContaAzul::CONFIG)->mapWithKeys(fn ($c) => [$c => Configuracao::ler($c, '')])->all(),
            'redirect' => ContaAzul::redirectUri(),
            'temSecret' => Configuracao::tem('contaazul_client_secret'),
            'configurado' => $ca->configurado(), 'conectado' => $ca->conectado(),
            'pendencias' => $ca->pendencias(), 'erroApi' => $erro,
        ]);
    }

    public function salvar(Request $request, ContaAzul $ca)
    {
        $request->validate(['contaazul_redirect_uri' => 'nullable|url']);
        $valores = $request->only(ContaAzul::CONFIG);
        if ($request->filled('contaazul_client_secret')) {
            $valores['contaazul_client_secret'] = $request->input('contaazul_client_secret');
        }
        Configuracao::salvar(array_map(fn ($v) => $v === null ? '' : $v, $valores));

        try {
            if ($request->input('acao') === 'criar_fornecedor') {
                $f = $ca->criarFornecedor(trim((string) $request->input('novo_fornecedor')) ?: 'Folha de Pagamento');
                Configuracao::salvar(['contaazul_fornecedor_id' => $f['id'], 'contaazul_fornecedor_nome' => $f['nome']]);

                return redirect()->route('contaazul')->with('mensagem', "Fornecedor \"{$f['nome']}\" criado no Conta Azul e selecionado.");
            }
        } catch (\Throwable $e) {
            return redirect()->route('contaazul')->withErrors(['contaazul' => $e->getMessage()]);
        }

        return redirect()->route('contaazul')->with('mensagem', 'Configurações do Conta Azul salvas.');
    }

    public function conectar(Request $request, ContaAzul $ca)
    {
        if (! $ca->configurado()) {
            return redirect()->route('contaazul')->withErrors(['contaazul' => 'Informe o Client ID e o Client Secret primeiro.']);
        }
        $state = Str::random(40);
        $request->session()->put('contaazul_state', $state);

        return redirect()->away($ca->urlAutorizacao($state));
    }

    public function retorno(Request $request, ContaAzul $ca)
    {
        $esperado = $request->session()->pull('contaazul_state');
        if (! $esperado || ! hash_equals($esperado, (string) $request->query('state'))) {
            return redirect()->route('contaazul')->withErrors(['contaazul' => 'Retorno do Conta Azul inválido (state não confere). Tente conectar de novo.']);
        }
        if (! $request->filled('code')) {
            return redirect()->route('contaazul')->withErrors(['contaazul' => 'O Conta Azul não autorizou: '.$request->query('error', 'sem código')]);
        }
        try {
            $ca->trocarCodigo((string) $request->query('code'));
        } catch (\Throwable $e) {
            return redirect()->route('contaazul')->withErrors(['contaazul' => $e->getMessage()]);
        }

        return redirect()->route('contaazul')->with('mensagem', 'Conta Azul conectado. Agora escolha fornecedor, conta e categorias.');
    }

    public function desconectar(ContaAzul $ca)
    {
        $ca->desconectar();

        return redirect()->route('contaazul')->with('mensagem', 'Conta Azul desconectado do portal.');
    }

    public function enviarFolha(Request $request, Folha $folha, ContaAzul $ca)
    {
        $request->validate(['vencimento' => 'required|date_format:Y-m-d']);
        $venc = \Carbon\Carbon::createFromFormat('Y-m-d', $request->input('vencimento'))->format('d/m/Y');
        try {
            $ca->enviarFolha($folha, $venc, $request->user()->name);
        } catch (\Throwable $e) {
            return redirect()->route('folha.show', $folha)->withErrors(['contaazul' => $e->getMessage()]);
        }

        return redirect()->route('folha.show', $folha)->with('mensagem', 'Conta a pagar criada no Conta Azul.');
    }
}
