<?php

use App\Http\Controllers\AdmissaoController;
use App\Http\Controllers\ConfiguracaoController;
use App\Http\Controllers\ContaAzulController;
use App\Http\Controllers\FolhaController;
use App\Http\Controllers\FuncaoController;
use App\Http\Controllers\FuncionarioController;
use App\Http\Controllers\LancamentoController;
use App\Http\Controllers\LoginController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'form'])->name('login');
    Route::post('/login', [LoginController::class, 'entrar'])->middleware('throttle:20,1');
});

Route::middleware('auth')->group(function () {
    Route::post('/sair', [LoginController::class, 'sair'])->name('sair');

    Route::get('/', [AdmissaoController::class, 'index'])->name('admissoes.index');
    Route::get('/admissoes/nova', [AdmissaoController::class, 'create'])->name('admissoes.create');
    Route::post('/admissoes', [AdmissaoController::class, 'store'])->name('admissoes.store');
    Route::get('/admissoes/{admissao}', [AdmissaoController::class, 'show'])->name('admissoes.show');
    Route::post('/admissoes/{admissao}', [AdmissaoController::class, 'update'])->name('admissoes.update');
    Route::get('/admissoes/{admissao}/documentos/{documento}', [AdmissaoController::class, 'documento'])->name('admissoes.documento');
    Route::post('/admissoes/{admissao}/apagar-documentos', [AdmissaoController::class, 'apagarDocumentos'])->name('admissoes.apagar');

    Route::get('/folha', [FolhaController::class, 'index'])->name('folha.index');
    Route::post('/folha', [FolhaController::class, 'importar'])->name('folha.importar');
    Route::post('/folha/adiantamento', [FolhaController::class, 'adiantamento'])->name('folha.adiantamento');
    Route::get('/folha/{folha}', [FolhaController::class, 'show'])->name('folha.show');
    Route::get('/folha/{folha}/pdf/{tipo}', [FolhaController::class, 'pdf'])->name('folha.pdf');

    Route::get('/lancamentos', [LancamentoController::class, 'index'])->name('lancamentos.index');
    Route::post('/lancamentos', [LancamentoController::class, 'salvar'])->name('lancamentos.salvar');
    Route::get('/lancamentos/txt', [LancamentoController::class, 'txt'])->name('lancamentos.txt');

    Route::get('/funcionarios', [FuncionarioController::class, 'index'])->name('funcionarios.index');
    Route::post('/funcionarios/{funcionario}', [FuncionarioController::class, 'update'])->name('funcionarios.update');
    Route::post('/funcionarios/{funcionario}/esocial', [FuncionarioController::class, 'esocial'])->name('funcionarios.esocial');

    Route::get('/alugueis', [\App\Http\Controllers\AluguelController::class, 'index'])->name('alugueis.index');
    Route::post('/alugueis', [\App\Http\Controllers\AluguelController::class, 'salvar'])->name('alugueis.salvar');

    Route::get('/funcoes', [FuncaoController::class, 'index'])->name('funcoes.index');
    Route::post('/funcoes', [FuncaoController::class, 'salvar'])->name('funcoes.salvar');
    Route::post('/funcoes/{funcao}/excluir', [FuncaoController::class, 'excluir'])->name('funcoes.excluir');

    Route::post('/folha/{folha}/contaazul', [ContaAzulController::class, 'enviarFolha'])->name('folha.contaazul');
    Route::get('/contaazul', [ContaAzulController::class, 'index'])->name('contaazul');
    Route::post('/contaazul', [ContaAzulController::class, 'salvar'])->name('contaazul.salvar');
    Route::get('/contaazul/conectar', [ContaAzulController::class, 'conectar'])->name('contaazul.conectar');
    Route::get('/contaazul/retorno', [ContaAzulController::class, 'retorno'])->name('contaazul.retorno');
    Route::post('/contaazul/desconectar', [ContaAzulController::class, 'desconectar'])->name('contaazul.desconectar');

    Route::get('/configuracoes', [ConfiguracaoController::class, 'index'])->name('configuracoes');
    Route::post('/configuracoes', [ConfiguracaoController::class, 'salvar'])->name('configuracoes.salvar');
    Route::post('/configuracoes/certificado', [ConfiguracaoController::class, 'certificado'])->name('configuracoes.certificado');
    Route::post('/configuracoes/certificado/remover', [ConfiguracaoController::class, 'removerCertificado'])->name('configuracoes.certificado.remover');
});
