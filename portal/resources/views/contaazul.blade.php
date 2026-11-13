@extends('layouts.app')
@section('titulo', 'Conta Azul')
@section('conteudo')
<p><a href="{{ route('configuracoes') }}">← Configurações</a></p>
<h1>Conta Azul — salários a pagar</h1>
@if (session('mensagem'))<div class="msg ok">{{ session('mensagem') }}</div>@endif
@if ($erroApi)<div class="msg erro">{{ $erroApi }}</div>@endif

<form method="post" action="{{ route('contaazul.salvar') }}" autocomplete="off">@csrf
  <div class="card">
    <h2 style="margin-top:0">1. Aplicação no Portal do Desenvolvedor</h2>
    <p style="color:var(--mut);margin-top:0">Crie uma aplicação em <a href="https://developers-portal.contaazul.com/" target="_blank" rel="noopener">developers-portal.contaazul.com</a>
      e cadastre nela a URL de redirecionamento abaixo, exatamente igual. O Client Secret fica criptografado e nunca é mostrado de volta.</p>
    <div class="grid">
      <div><label>Client ID</label><input name="contaazul_client_id" value="{{ $v['contaazul_client_id'] }}"></div>
      <div><label>Client Secret @if ($temSecret)<b style="color:var(--ok)">(salvo — deixe em branco para manter)</b>@endif</label>
        <input type="password" name="contaazul_client_secret" autocomplete="new-password"></div>
      <div style="grid-column:span 2"><label>URL de redirecionamento (em branco = padrão do portal)</label>
        <input name="contaazul_redirect_uri" value="{{ $v['contaazul_redirect_uri'] }}" placeholder="{{ route('contaazul.retorno') }}">
        <div class="dica" style="color:var(--mut)">Em uso: <code>{{ $redirect }}</code></div></div>
    </div>
    <p></p><button class="btn" name="acao" value="salvar">Salvar credenciais</button>
  </div>

  <div class="card">
    <h2 style="margin-top:0">2. Conexão</h2>
    @if ($conectado)
      <p style="margin-top:0"><span class="tag verde">Conectado</span> O portal renova o acesso sozinho.</p>
    @else
      <p style="margin-top:0;color:var(--mut)">Clique em conectar e entre com o usuário do <b>Conta Azul da empresa</b> (não o do Portal do Desenvolvedor) para autorizar o portal.</p>
      @if ($configurado)<a class="btn ok" href="{{ route('contaazul.conectar') }}">Conectar ao Conta Azul</a>
      @else<p class="dica">Salve o Client ID e o Client Secret primeiro.</p>@endif
    @endif
  </div>

  @if ($conectado)
  <div class="card">
    <h2 style="margin-top:0">3. Onde lançar</h2>
    <p style="color:var(--mut);margin-top:0">Cada folha vira <b>uma conta a pagar com o líquido total</b>: adiantamento vence no dia configurado, folha mensal no 5º dia útil do mês seguinte (dá para mudar a data na hora de enviar).</p>
    <div class="grid">
      <div><label>Fornecedor</label>
        <select name="contaazul_fornecedor_id" id="fornecedor">
          <option value="">— selecione —</option>
          @foreach ($fornecedores as $f)<option value="{{ $f['id'] }}" @selected($v['contaazul_fornecedor_id'] === $f['id'])>{{ $f['nome'] }}</option>@endforeach
        </select>
        <input type="hidden" name="contaazul_fornecedor_nome" id="fornecedor_nome" value="{{ $v['contaazul_fornecedor_nome'] }}"></div>
      <div><label>Conta financeira (de onde sai o pagamento)</label>
        <select name="contaazul_conta_financeira">
          <option value="">— selecione —</option>
          @foreach ($contas as $c)<option value="{{ $c['id'] }}" @selected($v['contaazul_conta_financeira'] === $c['id'])>{{ $c['nome'] }}</option>@endforeach
        </select></div>
      <div><label>Categoria da folha mensal</label>
        <select name="contaazul_categoria_folha">
          <option value="">— selecione —</option>
          @foreach ($categorias as $c)<option value="{{ $c['id'] }}" @selected($v['contaazul_categoria_folha'] === $c['id'])>{{ $c['nome'] }}</option>@endforeach
        </select></div>
      <div><label>Categoria do adiantamento</label>
        <select name="contaazul_categoria_adiantamento">
          <option value="">— selecione —</option>
          @foreach ($categorias as $c)<option value="{{ $c['id'] }}" @selected($v['contaazul_categoria_adiantamento'] === $c['id'])>{{ $c['nome'] }}</option>@endforeach
        </select></div>
    </div>
    <p></p><button class="btn pri" name="acao" value="salvar">Salvar escolhas</button>
    <div style="margin-top:14px;display:flex;gap:8px;align-items:end;flex-wrap:wrap">
      <div style="max-width:300px"><label>Não tem um fornecedor para a folha? Criar:</label><input name="novo_fornecedor" value="Folha de Pagamento"></div>
      <button class="btn" name="acao" value="criar_fornecedor">Criar fornecedor no Conta Azul</button>
    </div>
  </div>
  @endif
</form>
@if ($conectado)
<form method="post" action="{{ route('contaazul.desconectar') }}" onsubmit="return confirm('Desconectar o Conta Azul do portal?')">@csrf
  <button class="btn err">Desconectar</button>
</form>
@endif
<script>
  const f = document.getElementById('fornecedor');
  if (f) f.addEventListener('change', () => document.getElementById('fornecedor_nome').value = f.value ? f.options[f.selectedIndex].text : '');
</script>
@endsection
