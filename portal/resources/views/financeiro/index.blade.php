@extends('layouts.app')
@section('titulo', 'Financeiro')
@push('head')<style>
  .kpis { display:grid; grid-template-columns:repeat(auto-fill, minmax(180px,1fr)); gap:10px; margin-bottom:12px; }
  .kpis div { background:var(--card); border:1px solid var(--bd); border-radius:8px; padding:10px 12px; }
  .kpis span { display:block; font-size:12px; color:var(--mut); } .kpis b { font-size:20px; }
</style>@endpush
@section('conteudo')
@php use App\Support\Br; use App\Http\Controllers\FinanceiroController as F; @endphp
<div style="display:flex;justify-content:space-between;align-items:end;flex-wrap:wrap;gap:8px">
  <h1 style="margin-bottom:6px">Contas a pagar — {{ $dia->format('d/m/Y') }}
    <small style="color:var(--mut);font-weight:normal">{{ ucfirst($dia->locale('pt_BR')->dayName) }}{{ $dia->isToday() ? ' (hoje)' : '' }}</small></h1>
  <form method="get" style="display:flex;gap:6px;align-items:end">
    <a class="btn" href="{{ route('financeiro.index', ['dia' => $dia->copy()->subDay()->format('Y-m-d')]) }}">← Dia anterior</a>
    <input type="date" name="dia" value="{{ $dia->format('Y-m-d') }}" onchange="this.form.submit()" style="width:150px">
    <a class="btn" href="{{ route('financeiro.index', ['dia' => $dia->copy()->addDay()->format('Y-m-d')]) }}">Próximo dia →</a>
    @unless ($dia->isToday())<a class="btn" href="{{ route('financeiro.index') }}">Hoje</a>@endunless
    <a class="btn" href="{{ route('financeiro.index', ['dia' => $dia->format('Y-m-d'), 'atualizar' => 1]) }}" title="Buscar de novo no Conta Azul">Atualizar</a>
  </form>
</div>

@if (! $conectado)
  <div class="card"><p style="margin:0">Conta Azul não conectado. <a href="{{ route('contaazul') }}">Conectar</a></p></div>
@elseif ($erro)
  <div class="msg erro">Não consegui consultar o Conta Azul: {{ $erro }}</div>
@else
  <div class="kpis">
    <div><span>Total do dia</span><b>R$ {{ Br::moeda($totais['total']) }}</b><span>{{ count($contas) }} conta(s)</span></div>
    <div><span>Já pago</span><b style="color:var(--ok)">R$ {{ Br::moeda($totais['pago']) }}</b></div>
    <div><span>Em aberto no dia</span><b style="color:var(--warn)">R$ {{ Br::moeda($totais['em_aberto']) }}</b></div>
    <div><span>Atrasadas (60 dias antes)</span><b style="color:var(--err)">R$ {{ Br::moeda($totais['atrasado']) }}</b><span>{{ count($atrasadas) }} conta(s)</span></div>
  </div>

  @foreach ([['Vencendo em '.$dia->format('d/m/Y'), $contas, false], ['Atrasadas — ainda em aberto', $atrasadas, true]] as [$titulo, $lista, $mostraVenc])
    <div class="card" style="overflow-x:auto">
      <h2 style="margin-top:0">{{ $titulo }}</h2>
      <table>
        <tr>@if ($mostraVenc)<th>Vencimento</th>@endif<th>Fornecedor</th><th>Descrição</th><th>Categoria</th>
          <th class="n">Total</th><th class="n">Pago</th><th class="n">Em aberto</th><th>Situação</th></tr>
        @forelse ($lista as $c)
          @php [$rot, $cor] = F::STATUS[$c['status']] ?? [$c['status'], '']; @endphp
          <tr>
            @if ($mostraVenc)<td>{{ \Illuminate\Support\Carbon::parse($c['vencimento'])->format('d/m/Y') }}</td>@endif
            <td>{{ $c['fornecedor'] ?: '—' }}</td><td>{{ $c['descricao'] }}</td><td style="font-size:13px;color:var(--mut)">{{ $c['categoria'] }}</td>
            <td class="n">{{ Br::moeda($c['total']) }}</td><td class="n">{{ Br::moeda($c['pago']) }}</td>
            <td class="n"><b>{{ Br::moeda($c['em_aberto']) }}</b></td><td><span class="tag {{ $cor }}">{{ $rot }}</span></td>
          </tr>
        @empty
          <tr><td colspan="{{ $mostraVenc ? 8 : 7 }}" style="color:var(--mut)">Nenhuma conta.</td></tr>
        @endforelse
      </table>
    </div>
  @endforeach
  <p style="font-size:12px;color:var(--mut)">Dados do Conta Azul (atualiza a cada 2 minutos ou no botão Atualizar). Só leitura: nada é alterado no Conta Azul.</p>
@endif
@endsection
