<?php

use App\Http\Controllers\Api\AgenteController;
use Illuminate\Support\Facades\Route;

// API do agente (máquina/VM da Domínio). Autenticação: Authorization: Bearer <AGENTE_TOKEN>
Route::prefix('agente')->middleware(['agente', 'throttle:120,1'])->group(function () {
    Route::get('/tarefas/proxima', [AgenteController::class, 'proxima']);
    Route::post('/tarefas/{tarefa}/concluir', [AgenteController::class, 'concluir']);
    Route::post('/folhas', [AgenteController::class, 'enviarFolha']);
    Route::post('/funcionarios', [AgenteController::class, 'funcionarios']);
    Route::get('/certificado', [AgenteController::class, 'certificado'])->middleware('throttle:10,1');
});
