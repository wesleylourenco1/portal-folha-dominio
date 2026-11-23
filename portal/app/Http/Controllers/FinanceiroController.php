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
                $buscar = fn (string $de, string $ate) => Cache::remember("ca_pagar_{$de}_{$ate}", $request->boolean('atualizar') ? 0 : 120,
                    fn () => $ca->contasAPagar($de, $ate));
                $dados['contas'] = $buscar($dia->format('Y-m-d'), $dia->format('Y-m-d'));
                // vencidas nos 60 dias anteriores que ainda têm valor em aberto
                $dados['atrasadas'] = array_values(array_filter(
                    $buscar($dia->copy()->subDays(60)->format('Y-m-d'), $dia->copy()->subDay()->format('Y-m-d')),
                    fn ($c) => $c['em_aberto'] > 0 && ! in_array($c['status'], ['RENEGOCIADO', 'PERDIDO'], true)));
            } catch (\Throwable $e) {
                $dados['erro'] = $e->getMessage();
            }
        }
        $soma = fn (array $l, string $k) => round(array_sum(array_column($l, $k)), 2);
        $dados['totais'] = ['total' => $soma($dados['contas'], 'total'), 'pago' => $soma($dados['contas'], 'pago'),
            'em_aberto' => $soma($dados['contas'], 'em_aberto'), 'atrasado' => $soma($dados['atrasadas'], 'em_aberto')];

        return view('financeiro.index', $dados);
    }
}
