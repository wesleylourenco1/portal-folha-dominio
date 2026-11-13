@extends('layouts.app')
@section('titulo', 'Entrar')
@section('conteudo')
<div class="card" style="max-width:380px;margin:60px auto">
  <h1>Portal da Folha</h1>
  <form method="post" action="/login">@csrf
    <div style="margin-bottom:10px"><label for="email">E-mail</label>
      <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus autocomplete="username"></div>
    <div style="margin-bottom:10px"><label for="password">Senha</label>
      <input id="password" name="password" type="password" required autocomplete="current-password"></div>
    <label style="display:flex;gap:6px;align-items:center;margin-bottom:14px;color:var(--tx)">
      <input type="checkbox" name="lembrar" style="width:auto"> Manter conectado</label>
    <button class="btn pri" style="width:100%">Entrar</button>
  </form>
</div>
@endsection
