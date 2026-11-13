@extends('layouts.app')
@section('titulo', 'Lançamentos '.$comp)
@push('head')<style>
  table.lanc td, table.lanc th { padding:6px; }
  table.lanc input { width:100%; min-width:78px; padding:5px 6px; font-size:14px; text-align:right; }
  table.lanc input.txt { text-align:left; min-width:160px; }
  table.lanc td.erro input { border-color:var(--err); background:#fdecec; }
  table.lanc .dica { font-size:11px; color:var(--err); }
  table.lanc tr:hover td { background:#f7f9fc; }
  .barra { display:flex; gap:10px; align-items:end; flex-wrap:wrap; margin-bottom:14px; }
  tfoot td { font-weight:700; background:#f0f2f5; text-align:right; }
</style>@endpush
@section('conteudo')
@php use App\Support\Br; use App\Services\Lancamentos; $bloqueado = $st->fechada(); @endphp
<h1>Lançamentos do mês</h1>
<form method="get" class="barra">
  <div><label>Competência</label><input name="comp" value="{{ $comp }}" style="width:110px"></div>
  <button class="btn">Abrir</button>
  <span class="tag {{ $bloqueado ? 'azul' : 'amarela' }}">
    {{ $bloqueado ? 'Fechada por '.$st->por.' em '.$st->em?->format('d/m/Y H:i') : 'Aberta para preenchimento' }}</span>
</form>
@if ($msg)<div class="msg {{ $erros ? 'erro' : 'ok' }}">{{ $msg }}</div>@endif

<form method="post" action="{{ route('lancamentos.salvar') }}">@csrf
<input type="hidden" name="comp" value="{{ $comp }}">
<div class="card" style="overflow-x:auto;padding:10px">
<table class="lanc">
  <thead><tr><th>Funcionário</th>
    @foreach ($colunas as $campo => [$rotulo, $tipo, $rubrica])
      <th style="{{ $tipo !== 'texto' && $tipo !== 'datas' ? 'text-align:right' : '' }}">{{ $rotulo }}
        @if ($rubrica)<br><small style="color:var(--mut);font-weight:400">rubrica {{ $rubrica }}</small>@endif</th>
    @endforeach
  </tr></thead>
  <tbody>
  @foreach ($linhas as $l)
    @php $cod = $l['func']->codigo; @endphp
    <tr>
      <td style="min-width:220px"><b>{{ $cod }} – {{ $l['func']->nome }}</b><br>
        <small style="color:var(--mut)">{{ $l['func']->local ?: $l['func']->cargo }}@if ($l['atualizado']) · salvo {{ $l['atualizado'] }}@endif</small></td>
      @foreach ($colunas as $campo => [$rotulo, $tipo])
        @php $err = $erros[$cod][$campo] ?? null; $valor = $l['valores'][$campo] ?? ''; @endphp
        <td class="{{ $err ? 'erro' : '' }}">
          <input name="v[{{ $cod }}][{{ $campo }}]" value="{{ $valor }}" class="{{ in_array($tipo, ['texto', 'datas']) ? 'txt' : '' }}"
            @if ($bloqueado) readonly @endif
            placeholder="{{ ['horas' => '0:00', 'moeda' => '0,00', 'datas' => 'ex.: 5, 12'][$tipo] ?? '' }}"
            @if ($campo === 'comissao' && ! $l['func']->comissionado) title="Funcionário não marcado como comissionado" @endif>
          @if ($err)<div class="dica">{{ $err }}</div>
          @elseif ($tipo === 'datas' && $valor)<div style="font-size:11px;color:var(--mut)">{{ Lancamentos::contarDatas($valor) }} dia(s)</div>@endif
        </td>
      @endforeach
    </tr>
  @endforeach
  </tbody>
  <tfoot><tr><td style="text-align:left">Total ({{ count($linhas) }})</td>
    <td>{{ $tot['horas_extras'] }}</td><td>{{ $tot['faltas'] }}</td><td>{{ $tot['faltas_vr'] }}</td>
    <td>{{ Br::moeda($tot['comissao']) }}</td><td>{{ Br::moeda($tot['desconto_vr']) }}</td><td></td></tr></tfoot>
</table>
</div>
<div style="display:flex;gap:8px;flex-wrap:wrap">
  @unless ($bloqueado)
    <button class="btn pri" name="acao" value="salvar">Salvar</button>
    <button class="btn ok" name="acao" value="fechar" onclick="return confirm('Fechar a competência {{ $comp }}? Depois de fechada, ninguém altera sem reabrir.')">Salvar e fechar competência</button>
  @else
    <button class="btn" name="acao" value="reabrir" onclick="return confirm('Reabrir a competência {{ $comp }} para alterações?')">Reabrir competência</button>
  @endunless
  <a class="btn" href="{{ route('lancamentos.txt', ['comp' => $comp]) }}"
     title="Arquivo no leiaute de importação de lançamentos da Domínio (empresa, rubricas e formato em Configurações)">⬇ Gerar TXT para a Domínio</a>
</div>
</form>
<p style="color:var(--mut);font-size:13px;margin-top:14px">Dias de falta: informe os dias do mês separados por vírgula (ex.: 5, 12, 20).
  Horas no formato 18:14 (também aceita 18h14 ou 18,5). Desconto VR vem preenchido com o valor padrão do funcionário.</p>
@endsection
