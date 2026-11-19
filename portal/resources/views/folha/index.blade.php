@extends('layouts.app')
@section('titulo', 'Folha de pagamento')
@section('conteudo')
@php use App\Support\Br; @endphp
<h1>Folha de pagamento</h1>

<div class="card">
  <h2 style="margin-top:0">Adiantamento (vale)</h2>
  <p style="color:var(--mut);margin-top:0">O robô dispara a rotina automática da Domínio (consignados → cálculo → Extrato e Líquidos em PDF)
    e os PDFs chegam aqui sozinhos. Você recebe um e-mail quando estiver pronto.</p>
  @php $online = $agenteVisto && (time() - $agenteVisto) < 180; @endphp
  <p style="margin:0 0 10px">Robô da Domínio:
    @if ($online)<span class="tag verde">online</span>
    @else<span class="tag vermelha">offline</span> <small style="color:var(--mut)">{{ $agenteVisto ? 'visto pela última vez em '.\Illuminate\Support\Carbon::createFromTimestamp($agenteVisto, 'America/Sao_Paulo')->format('d/m/Y H:i') : 'nunca conectou' }} — os pedidos ficam na fila até ele voltar</small>@endif
  </p>
  @if ($cfg['adiantamento_status'])
    <div class="msg {{ str_starts_with($cfg['adiantamento_status'], 'ERRO') ? 'erro' : (str_starts_with($cfg['adiantamento_status'], 'OK') ? 'ok' : '') }}">{{ $cfg['adiantamento_status'] }}</div>
  @endif
  <form method="post" action="{{ route('folha.adiantamento') }}" style="display:flex;gap:10px;flex-wrap:wrap;align-items:end;margin-bottom:14px">@csrf
    <div><label>Competência</label><input name="competencia" value="{{ $pendente->payload['competencia'] ?? $compAtual }}" style="width:110px"></div>
    <button class="btn pri" name="acao" value="gerar" @if ($pendente) disabled @endif>
      {{ $pendente ? 'Na fila do robô...' : 'Gerar adiantamento agora' }}</button>
  </form>
  <form method="post" action="{{ route('folha.adiantamento') }}" style="display:flex;gap:14px;flex-wrap:wrap;align-items:end">@csrf
    <label style="display:flex;gap:6px;align-items:center;font-size:14px;color:var(--tx)">
      <input type="checkbox" name="adiantamento_automatico" value="1" style="width:auto" @checked($cfg['adiantamento_automatico'] === 'sim')> Gerar automaticamente todo mês</label>
    <div><label>a partir do dia</label><input name="adiantamento_dia_gerar" value="{{ $cfg['adiantamento_dia_gerar'] }}" style="width:70px"></div>
    <div><label>Vencimento (dia)</label><input name="adiantamento_dia_vencimento" value="{{ $cfg['adiantamento_dia_vencimento'] }}" style="width:70px"></div>
    <button class="btn" name="acao" value="salvar">Salvar</button>
  </form>
</div>

<div class="card">
  <h2 style="margin-top:0">Importar extrato</h2>
  <p style="color:var(--mut);margin-top:0">Na Domínio Folha, gere o relatório <b>Extrato Mensal</b> do cálculo (Folha Mensal ou Adiantamento),
    salve em PDF e envie aqui. O portal guarda o PDF e gera a relação de líquidos.</p>
  <form method="post" action="{{ route('folha.importar') }}" enctype="multipart/form-data" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">@csrf
    <input type="file" name="extrato" accept=".pdf" required style="max-width:420px">
    <button class="btn pri">Importar</button>
  </form>
</div>

<div class="card" style="overflow-x:auto">
<table>
  <tr><th>Competência</th><th>Empresa</th><th>Cálculo</th><th class="n">Funcionários</th>
      <th class="n">Proventos</th><th class="n">Descontos</th><th class="n">Líquido</th><th>eSocial</th><th>Conta Azul</th><th>Importado</th></tr>
  @forelse ($folhas as $f)
    @php $d = $f->dados; @endphp
    <tr>
      <td><a href="{{ route('folha.show', $f) }}"><b>{{ $f->competencia }}</b></a></td>
      <td>{{ $f->empresa_codigo }} - {{ $f->empresa_nome }}</td>
      <td>@if ($f->ehAdiantamento())<span class="tag amarela">{{ $f->calculo }}</span>@else{{ $f->calculo }}@endif</td>
      <td class="n">{{ count($d['funcionarios']) }}</td>
      <td class="n">{{ Br::moeda($d['total_proventos']) }}</td>
      <td class="n">{{ Br::moeda($d['total_descontos']) }}</td>
      <td class="n"><b>{{ Br::moeda($d['total_liquido']) }}</b></td>
      <td>@if ($f->ehAdiantamento())<span style="color:var(--mut)">—</span>
        @elseif ($f->esocial_status)<span class="tag {{ ['transmitido' => 'verde', 'erro' => 'vermelha', 'validado' => 'azul', 'na_fila' => 'azul', 'aguardando' => 'azul', 'fechando' => 'azul'][$f->esocial_status] ?? 'amarela' }}"
          title="{{ $f->esocial_mensagem }}">{{ \App\Services\EsocialStatusFolha::ROTULOS[$f->esocial_status] ?? $f->esocial_status }}</span>
        @else<span class="tag amarela">Não transmitido</span>@endif</td>
      <td>@if ($f->contaazul_id)<span class="tag verde" title="Vencimento {{ $f->contaazul_vencimento }}">Lançado</span>@else<span style="color:var(--mut)">—</span>@endif</td>
      <td>{{ $f->updated_at->format('d/m/Y H:i') }}</td>
    </tr>
  @empty
    <tr><td colspan="10">Nenhuma folha importada.</td></tr>
  @endforelse
</table>
</div>
@endsection
