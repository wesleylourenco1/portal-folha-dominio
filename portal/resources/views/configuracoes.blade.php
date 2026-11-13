@extends('layouts.app')
@section('titulo', 'Configurações')
@section('conteudo')
<h1>Configurações</h1>
@if ($mensagem)<div class="msg {{ $erro ? 'erro' : 'ok' }}">{{ $mensagem }}</div>@endif
<form method="post" action="{{ route('configuracoes.salvar') }}" autocomplete="off">@csrf
  <div class="card">
    <h2 style="margin-top:0">Inteligência artificial (leitura dos documentos)</h2>
    <p style="color:var(--mut);margin-top:0">A chave fica criptografada no banco e nunca é mostrada de volta.</p>
    <div class="grid">
      <div><label>Provedor</label><select name="ia_provedor" id="ia_provedor">
        @foreach ($provedores as $k => $p)<option value="{{ $k }}" @selected(($v['ia_provedor'] ?: 'anthropic') === $k)>{{ $p['nome'] }}</option>@endforeach
      </select></div>
      <div><label>Modelo (em branco = padrão)</label><input name="ia_modelo" id="ia_modelo" value="{{ $v['ia_modelo'] }}"></div>
      <div style="grid-column:span 2"><label>Chave da API @if ($salvos['ia_chave'])<b style="color:var(--ok)">(chave salva — deixe em branco para manter)</b>@endif</label>
        <input type="password" name="ia_chave" placeholder="sk-..." autocomplete="new-password"></div>
    </div>
    <p style="color:var(--mut);font-size:13px" id="dica_ia"></p>
    <button class="btn" name="acao" value="testar_ia">Salvar e testar chave</button>
  </div>

  <div class="card">
    <h2 style="margin-top:0">E-mail de avisos</h2>
    <p style="color:var(--mut);margin-top:0">Gmail: <code>smtp.gmail.com</code>, porta 587, senha de app. Office 365: <code>smtp.office365.com</code>, porta 587.</p>
    <div class="grid">
      <div><label>Servidor SMTP</label><input name="smtp_servidor" value="{{ $v['smtp_servidor'] }}"></div>
      <div><label>Porta</label><input name="smtp_porta" value="{{ $v['smtp_porta'] ?: '587' }}"></div>
      <div><label>Usuário</label><input name="smtp_usuario" value="{{ $v['smtp_usuario'] }}"></div>
      <div><label>Senha @if ($salvos['smtp_senha'])<b style="color:var(--ok)">(salva)</b>@endif</label><input type="password" name="smtp_senha" autocomplete="new-password"></div>
      <div><label>Remetente (opcional)</label><input name="smtp_remetente" value="{{ $v['smtp_remetente'] }}"></div>
      <div><label>Enviar avisos para</label><input name="email_destino" value="{{ $v['email_destino'] }}"></div>
    </div>
    <p></p><button class="btn" name="acao" value="testar_email">Salvar e enviar e-mail de teste</button>
  </div>

  <div class="card">
    <h2 style="margin-top:0">Agente da Domínio</h2>
    <p style="color:var(--mut);margin:0">O agente (máquina ou VM com a Domínio) se conecta ao portal com o <code>AGENTE_TOKEN</code> do arquivo <code>.env</code>
      — gere com <code>php artisan portal:token-agente</code>. Usuário e senha da Domínio ficam só na máquina do agente.</p>
  </div>

  <button class="btn pri" name="acao" value="salvar">Salvar tudo</button>
</form>
<script>
  const provedores = @json($provedores);
  const sel = document.getElementById('ia_provedor');
  function dica() {
    const p = provedores[sel.value];
    document.getElementById('ia_modelo').placeholder = p.modelo_padrao;
    document.getElementById('dica_ia').textContent = 'Modelo padrão: ' + p.modelo_padrao + '. Crie a chave em ' + p.site + '. Ao trocar de provedor, informe a chave do novo provedor.';
  }
  sel.addEventListener('change', dica); dica();
</script>
@endsection
