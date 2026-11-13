<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="{{ csrf_token() }}">
<title>@yield('titulo', 'Portal da Folha')</title>
<style>
  :root { --bg:#f5f6f8; --card:#fff; --tx:#1d2330; --mut:#667085; --bd:#d9dde3; --pri:#1f5fbf; --ok:#1a7f37; --warn:#b26a00; --err:#c62828; }
  * { box-sizing:border-box; }
  body { margin:0; font:15px/1.45 "Segoe UI", system-ui, sans-serif; background:var(--bg); color:var(--tx); }
  header { background:#16325c; color:#fff; padding:12px 20px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px; }
  header a, header button.link { color:#fff; text-decoration:none; font-weight:600; background:none; border:0; font:inherit; cursor:pointer; padding:0; }
  nav { display:flex; gap:18px; align-items:center; flex-wrap:wrap; }
  main { max-width:1400px; margin:0 auto; padding:20px; }
  .card { background:var(--card); border:1px solid var(--bd); border-radius:8px; padding:16px 20px; margin-bottom:16px; }
  h1 { font-size:22px; margin:0 0 16px; } h2 { font-size:16px; margin:18px 0 10px; color:#16325c; }
  table { width:100%; border-collapse:collapse; } th, td { text-align:left; padding:8px 10px; border-bottom:1px solid var(--bd); }
  th { font-size:13px; color:var(--mut); font-weight:600; } td.n, th.n { text-align:right; white-space:nowrap; }
  .btn { display:inline-block; border:0; border-radius:6px; padding:9px 16px; font:inherit; font-weight:600; cursor:pointer; text-decoration:none; background:#e4e7ec; color:var(--tx); }
  .btn.pri { background:var(--pri); color:#fff; } .btn.ok { background:var(--ok); color:#fff; } .btn.err { background:var(--err); color:#fff; }
  .btn:disabled { opacity:.6; cursor:default; }
  .grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(230px, 1fr)); gap:10px 16px; }
  label { display:block; font-size:13px; color:var(--mut); margin-bottom:3px; }
  input, select, textarea { width:100%; padding:7px 9px; border:1px solid var(--bd); border-radius:5px; font:inherit; background:#fff; }
  .incerto input { border-color:var(--warn); background:#fff8e6; } .erro input { border-color:var(--err); background:#fdecec; }
  .dica { font-size:12px; margin-top:2px; } .incerto .dica { color:var(--warn); } .erro .dica { color:var(--err); }
  .tag { display:inline-block; padding:2px 8px; border-radius:10px; font-size:12px; font-weight:600; background:#e4e7ec; }
  .tag.verde { background:#dcf3e2; color:var(--ok); } .tag.amarela { background:#fff1cc; color:var(--warn); }
  .tag.vermelha { background:#fdecec; color:var(--err); } .tag.azul { background:#e3ecfb; color:var(--pri); }
  .msg { padding:10px 14px; border-radius:6px; background:#fff1cc; margin-bottom:12px; }
  .msg.ok { background:#dcf3e2; color:var(--ok); } .msg.erro { background:#fdecec; color:var(--err); }
  pre { background:#f0f2f5; padding:10px; border-radius:6px; white-space:pre-wrap; font-size:12px; }
  @media (max-width: 700px) { main { padding:12px; } th, td { padding:6px; } }
</style>
@stack('head')
</head>
<body>
@auth
<header>
  <a href="{{ route('admissoes.index') }}">Portal da Folha</a>
  <nav>
    <a href="{{ route('admissoes.index') }}">Admissões</a>
    <a href="{{ route('lancamentos.index') }}">Lançamentos</a>
    <a href="{{ route('folha.index') }}">Folha</a>
    <a href="{{ route('funcionarios.index') }}">Funcionários</a>
    <a href="{{ route('funcoes.index') }}">Funções</a>
    <a href="{{ route('configuracoes') }}">Configurações</a>
    <form method="post" action="{{ route('sair') }}" style="margin:0">@csrf
      <button class="link" style="opacity:.75" title="Sair">{{ auth()->user()->name }} · sair</button></form>
  </nav>
</header>
@endauth
<main>
  @if ($errors->any())<div class="msg erro">{{ $errors->first() }}</div>@endif
  @yield('conteudo')
</main>
</body>
</html>
