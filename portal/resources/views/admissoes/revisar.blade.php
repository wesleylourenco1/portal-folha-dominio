@extends('layouts.app')
@section('titulo', 'Admissão #'.$adm->id)
@push('head')
@if (in_array($adm->status, ['extraindo', 'aprovado', 'cadastrando']))<meta http-equiv="refresh" content="5">@endif
<style>
  .lado { display:grid; grid-template-columns:minmax(0,1.3fr) minmax(0,1fr); gap:16px; align-items:start; }
  .visor { position:sticky; top:10px; }
  .visor iframe { width:100%; height:78vh; border:1px solid var(--bd); border-radius:6px; background:#fff; }
  .abas a { margin:0 6px 6px 0; font-size:13px; padding:5px 10px; }
  @media (max-width: 900px) { .lado { grid-template-columns:1fr; } .visor { position:static; } }
</style>
@endpush
@section('conteudo')
<h1>Admissão #{{ $adm->id }} — {{ ($adm->dados['nome'] ?? '') ?: 'sem nome' }} @include('admissoes._status', ['status' => $adm->status]) @if (! empty($adm->dados['_teste']))<span class="tag amarela">TESTE</span>@endif</h1>
@if ($adm->mensagem)<div class="msg">{{ $adm->mensagem }}</div>@endif
@if ($adm->status === 'extraindo')<div class="msg">A IA está lendo os documentos. Esta página atualiza sozinha.</div>@endif
@if (! empty($adm->extracao['observacoes_extracao']))<div class="msg"><b>Observações da leitura:</b> {{ $adm->extracao['observacoes_extracao'] }}</div>@endif

<div class="lado">
<form method="post" action="{{ route('admissoes.update', $adm) }}" class="card">@csrf
  @php $secao = null; @endphp
  @foreach ($campos as [$sec, $campo, $rotulo, $origem])
    @if ($sec !== $secao)
      @if ($secao)</div>@endif
      <h2>{{ $sec }}</h2><div class="grid">
      @php $secao = $sec; @endphp
    @endif
    <div class="{{ isset($erros[$campo]) ? 'erro' : (in_array($campo, $incertos) ? 'incerto' : '') }}">
      <label for="{{ $campo }}">{{ $rotulo }}</label>
      @include('admissoes._campo', ['campo' => $campo, 'valor' => $adm->dados[$campo] ?? '', 'somenteLeitura' => ! $editavel])
      @if (isset($erros[$campo]))<div class="dica">{{ $erros[$campo] }}</div>
      @elseif (in_array($campo, $incertos))<div class="dica">Leitura duvidosa: confira no documento</div>@endif
    </div>
  @endforeach
  </div>
  @if ($editavel)
  <div style="margin-top:18px;display:flex;gap:8px;flex-wrap:wrap">
    <button class="btn" name="acao" value="salvar">Salvar</button>
    <button class="btn" name="acao" value="reextrair" onclick="return confirm('Ler os documentos novamente? Campos vazios serão preenchidos.')">Ler documentos de novo</button>
    @if (empty($adm->dados['_teste']))<button class="btn ok" name="acao" value="aprovar" onclick="return confirm('Salvar e enviar para o robô cadastrar na Domínio?')">Salvar e enviar para a Domínio</button>@else<button class="btn" disabled title="Admissão de teste">Salvar e enviar para a Domínio (desativado no teste)</button>@endif
  </div>
  @endif
</form>

<div class="visor card">
  <div class="abas">
    @foreach ($adm->documentos as $doc)
      <a class="btn" href="{{ route('admissoes.documento', [$adm, $doc->id]) }}" target="visor">{{ $doc->nome_original }}</a>
    @endforeach
  </div>
  @if ($adm->documentos->isNotEmpty())
    <iframe name="visor" src="{{ route('admissoes.documento', [$adm, $adm->documentos->first()->id]) }}"></iframe>
  @else
    <p>Documentos apagados.</p>
  @endif
  @if (in_array($adm->status, ['aguardando_esocial', 'concluido']) && $adm->documentos->isNotEmpty())
  <form method="post" action="{{ route('admissoes.apagar', $adm) }}" onsubmit="return confirm('Apagar definitivamente os documentos deste funcionário do portal?')">@csrf
    <button class="btn err" style="margin-top:10px">Apagar documentos (LGPD)</button>
  </form>
  @endif
</div>
</div>

<div class="card"><h2 style="margin-top:0">Histórico</h2><pre>{{ $adm->log }}</pre></div>
@include('admissoes._experiencia_js')
@endsection
