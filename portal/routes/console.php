<?php

use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

// Cria (ou atualiza a senha de) um usuário do portal. A senha é pedida no terminal, nunca em argumento.
Artisan::command('portal:usuario {email} {--nome=}', function (string $email) {
    $senha = $this->secret('Senha (mínimo 10 caracteres)');
    if (strlen((string) $senha) < 10) {
        $this->error('Senha muito curta.');

        return 1;
    }
    $usuario = User::updateOrCreate(['email' => $email], [
        'name' => $this->option('nome') ?: Str::before($email, '@'),
        'password' => Hash::make($senha),
    ]);
    $this->info("Usuário {$usuario->email} pronto.");
})->purpose('Cria ou atualiza um usuário do portal');

// Gera um token forte para o agente e mostra a linha para colar no .env do portal e do agente.
Artisan::command('portal:token-agente', function () {
    $token = Str::random(64);
    $this->line('Coloque no .env do PORTAL e no .env do AGENTE:');
    $this->info("AGENTE_TOKEN={$token}");
})->purpose('Gera o token de acesso do agente');
