<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Autentica o agente da máquina da Domínio pelo token do .env (AGENTE_TOKEN).
 * Comparação em tempo constante; sem token configurado a API fica fechada.
 */
class AutenticarAgente
{
    public function handle(Request $request, Closure $next): Response
    {
        $esperado = (string) config('services.agente.token');
        $recebido = (string) $request->bearerToken();

        if (strlen($esperado) < 32 || ! hash_equals($esperado, $recebido)) {
            return response()->json(['erro' => 'Não autorizado'], 401);
        }

        return $next($request);
    }
}
