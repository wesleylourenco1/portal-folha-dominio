<?php

namespace App\Http\Controllers;

use App\Services\ContaAzul;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/** Financeiro: contas a pagar do dia (e atrasadas) lidas do Conta Azul. */
class FinanceiroController extends Controller
{
    public const STATUS = [
        'EM_ABERTO' => ['Em aberto', 'amarela'], 'ATRASADO' => ['Atrasado', 'vermelha'], 'RECEBIDO' => ['Pago', 'verde'],
        'RECEBIDO_PARCIAL' => ['Pago parcial', 'azul'], 'RENEGOCIADO' => ['Renegociado', ''], 'PERDIDO' => ['Perdido', ''],
    ];

    public function index(Request $request, ContaAzul $ca)
    {
        try {
            $dia = Carbon::createFromFormat('Y-m-d', (string) $request->query('dia', now()->format('Y-m-d')))->startOfDay();
        } catch (\Throwable) {
            $dia = now()->startOfDay();
        }
        $dados = ['dia' => $dia, 'contas' => [], 'atrasadas' => [], 'erro' => null, 'conectado' => $ca->conectado()];
        if ($dados['conectado']) {
            try {
                $ttl = $request->boolean('atualizar') ? 0 : 120;
                $buscar = function (string $de, string $ate) use ($ca, $ttl) {
                    $contas = Cache::remember("ca_pagar_{$de}_{$ate}", $ttl, fn () => $ca->contasAPagar($de, $ate));
                    // a busca não diz a conta financeira: pergunta cartão por cartão quais contas são dele
                    $noCartao = [];
                    foreach (Cache::remember('ca_cartoes', 3600, fn () => $ca->cartoesCredito()) as $id => $nome) {
                        foreach (Cache::remember("ca_pagar_{$de}_{$ate}_{$id}", $ttl, fn () => $ca->contasAPagar($de, $ate, $id)) as $c) {
                            $noCartao[$c['id']] = $nome;
                        }
                    }

                    return array_map(fn ($c) => $c + ['cartao' => $noCartao[$c['id']] ?? null], $contas);
                };
                $dados['contas'] = $buscar($dia->format('Y-m-d'), $dia->format('Y-m-d'));
                // vencidas nos 60 dias anteriores que ainda têm valor em aberto
                $dados['atrasadas'] = array_values(array_filter(
                    $buscar($dia->copy()->subDays(60)->format('Y-m-d'), $dia->copy()->subDay()->format('Y-m-d')),
                    fn ($c) => $c['em_aberto'] > 0 && ! in_array($c['status'], ['RENEGOCIADO', 'PERDIDO'], true)));
                $forma = $request->query('forma');
                if (in_array($forma, ['cartao', 'outros'], true)) {
                    $filtro = fn ($c) => $forma === 'cartao' ? $c['cartao'] !== null : $c['cartao'] === null;
                    $dados['contas'] = array_values(array_filter($dados['contas'], $filtro));
                    $dados['atrasadas'] = array_values(array_filter($dados['atrasadas'], $filtro));
                }
            } catch (\Throwable $e) {
                $dados['erro'] = $e->getMessage();
            }
        }
        $soma = fn (array $l, string $k) => round(array_sum(array_column($l, $k)), 2);
        $dados['totais'] = ['total' => $soma($dados['contas'], 'total'), 'pago' => $soma($dados['contas'], 'pago'),
            'em_aberto' => $soma($dados['contas'], 'em_aberto'), 'atrasado' => $soma($dados['atrasadas'], 'em_aberto'),
            'cartao' => $soma(array_filter($dados['contas'], fn ($c) => ($c['cartao'] ?? null) !== null), 'total')];
        $dados['forma'] = $request->query('forma');

        return view('financeiro.index', $dados);
    }
}
