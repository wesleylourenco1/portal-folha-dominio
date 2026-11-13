<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class LoginController extends Controller
{
    public function form()
    {
        return view('login');
    }

    public function entrar(Request $request)
    {
        $dados = $request->validate(['email' => 'required|email', 'password' => 'required']);
        $chave = 'login:'.Str::lower($dados['email']).'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($chave, 5)) {
            return back()->withErrors(['email' => 'Muitas tentativas. Aguarde '.RateLimiter::availableIn($chave).'s.'])->onlyInput('email');
        }
        if (! Auth::attempt($dados, $request->boolean('lembrar'))) {
            RateLimiter::hit($chave, 60);

            return back()->withErrors(['email' => 'E-mail ou senha inválidos.'])->onlyInput('email');
        }
        RateLimiter::clear($chave);
        $request->session()->regenerate();

        return redirect()->intended('/');
    }

    public function sair(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/login');
    }
}
