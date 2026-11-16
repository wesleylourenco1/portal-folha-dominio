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
    <h2 style="margin-top:0">Domínio — TXT de lançamentos</h2>
    <p style="color:var(--mut);margin-top:0">Usado no botão "Gerar TXT para a Domínio" da tela de Lançamentos. Teste o arquivo numa competência de teste
      antes de usar em produção (tipo de processo e formato das horas variam conforme a instalação).</p>
    <div class="grid">
      <div><label>Código da empresa na Domínio</label><input name="dominio_empresa_codigo" value="{{ $v['dominio_empresa_codigo'] }}"></div>
      <div><label>Tipo do processo (2 dígitos)</label><input name="dominio_tipo_processo" value="{{ $v['dominio_tipo_processo'] }}"></div>
      <div><label>Formato das horas</label><select name="dominio_formato_horas">
        <option value="hhmm" @selected($v['dominio_formato_horas'] === 'hhmm')>18:14 → 18,14 (horas e minutos)</option>
        <option value="centesimal" @selected($v['dominio_formato_horas'] === 'centesimal')>18:14 → 18,23 (centesimal)</option>
      </select></div>
      <div><label>Rubrica horas extras</label><input name="dominio_rubrica_horas_extras" value="{{ $v['dominio_rubrica_horas_extras'] }}"></div>
      <div><label>Rubrica dias de falta</label><input name="dominio_rubrica_faltas" value="{{ $v['dominio_rubrica_faltas'] }}"></div>
      <div><label>Rubrica falta VR (vazio = não exporta)</label><input name="dominio_rubrica_faltas_vr" value="{{ $v['dominio_rubrica_faltas_vr'] }}"></div>
      <div><label>Rubrica comissão</label><input name="dominio_rubrica_comissao" value="{{ $v['dominio_rubrica_comissao'] }}"></div>
      <div><label>Rubrica desconto VR</label><input name="dominio_rubrica_desconto_vr" value="{{ $v['dominio_rubrica_desconto_vr'] }}"></div>
    </div>
  </div>

  <div class="card">
    <h2 style="margin-top:0">eSocial — acompanhamento dos funcionários</h2>
    <p style="color:var(--mut);margin-top:0">O robô da Domínio consulta o eSocial com o <b>certificado A1 (e-CNPJ) que fica só na máquina dele</b>
      (<code>ESOCIAL_CERTIFICADO</code> no .env do agente) e manda para cá só o resultado: recibo da admissão e eventos recebidos.
      Regras do eSocial: até 10 consultas por dia, nenhuma entre os dias 1 e 7. Status aparece em Funcionários.</p>
    <div class="grid">
      <div><label>Consultar automaticamente</label><select name="esocial_ativo">
        <option value="nao" @selected(($v['esocial_ativo'] ?: 'nao') === 'nao')>Não</option>
        <option value="sim" @selected($v['esocial_ativo'] === 'sim')>Sim, todo dia a partir das 8 h</option></select></div>
      <div><label>Consultas por dia (1 a 9)</label><input name="esocial_consultas_dia" value="{{ $v['esocial_consultas_dia'] ?: '8' }}"></div>
      <div><label>CNPJ do empregador (em branco = da última folha)</label>
        <input name="esocial_cnpj" value="{{ $v['esocial_cnpj'] }}" placeholder="{{ $esocialCnpjFolha }}"></div>
      <div><label>Ambiente</label><select name="esocial_ambiente">
        <option value="producao" @selected(($v['esocial_ambiente'] ?: 'producao') === 'producao')>Produção</option>
        <option value="restrita" @selected($v['esocial_ambiente'] === 'restrita')>Produção restrita (testes)</option></select></div>
    </div>
    @if ($esocialStatus)<p style="margin-bottom:0;font-size:13px">Última execução: {{ $esocialStatus }}</p>@endif
    @if ($esocialValidade)<p style="margin:4px 0 0;font-size:13px">Certificado válido até <b>{{ $esocialValidade }}</b>.</p>@endif
  </div>

  <div class="card">
    <h2 style="margin-top:0">Conta Azul</h2>
    <p style="color:var(--mut);margin-top:0">Lança o líquido de cada folha (adiantamento e mensal) como conta a pagar.</p>
    <a class="btn" href="{{ route('contaazul') }}">Configurar Conta Azul →</a>
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
