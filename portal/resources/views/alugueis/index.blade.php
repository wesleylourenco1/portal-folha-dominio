@extends('layouts.app')
@section('titulo', 'Aluguel '.$a->competencia)
@push('head')<style>
  .itens td { padding:4px 6px; } .itens input, .itens select { padding:5px 7px; }
  .res { display:grid; grid-template-columns:repeat(auto-fill, minmax(190px,1fr)); gap:10px; }
  .res div { background:var(--card); border:1px solid var(--bd); border-radius:8px; padding:10px 12px; }
  .res span { display:block; font-size:12px; color:var(--mut); } .res b { font-size:18px; }
  .res .digitar { border-color:var(--pri); } .res .digitar b { color:var(--pri); }
</style>@endpush
@section('conteudo')
@php use App\Support\Br; use App\Models\Aluguel; $ro = in_array($a->status, ['na_fila', 'lancado'], true); @endphp
<div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px">
  <h1>Aluguel {{ $a->competencia }} @if ($a->exists)<span class="tag {{ ['lancado' => 'verde', 'na_fila' => 'azul', 'erro' => 'vermelha'][$a->status] ?? '' }}">{{ Aluguel::STATUS[$a->status] ?? $a->status }}</span>@endif</h1>
  <form method="get" style="display:flex;gap:6px;align-items:end"><div><label>Competência</label>
    <input name="competencia" value="{{ $a->competencia }}" style="width:100px" placeholder="MM/AAAA"></div><button class="btn">Abrir</button></form>
</div>
@if (session('msg'))<div class="msg ok">{{ session('msg') }}</div>@endif
@if ($a->mensagem)<div class="msg {{ $a->status === 'erro' ? 'erro' : '' }}">{{ $a->mensagem }}</div>@endif

<form method="post" action="{{ route('alugueis.salvar') }}">@csrf
  <input type="hidden" name="competencia" value="{{ $a->competencia }}">
  <div class="card">
    <h2 style="margin-top:0">1. Correspondência da imobiliária</h2>
    <p style="color:var(--mut);margin-top:0">Digite as linhas do e-mail (entradas) e o IRRF que a imobiliária descontou. A competência é o mês do aluguel.</p>
    <table class="itens">
      <tr><th>Tipo</th><th>Descrição</th><th class="n">Valor (R$)</th></tr>
      @foreach (array_pad($a->itens ?? [], max(8, count($a->itens ?? []) + 2), ['tipo' => 'aluguel', 'descricao' => '', 'valor' => '']) as $k => $i)
      <tr>
        <td><select name="itens[{{ $k }}][tipo]" @disabled($ro)>@foreach (Aluguel::TIPOS as $t => $rot)<option value="{{ $t }}" @selected($i['tipo'] === $t)>{{ $rot }}</option>@endforeach</select></td>
        <td><input name="itens[{{ $k }}][descricao]" value="{{ $i['descricao'] }}" style="min-width:320px" @readonly($ro)></td>
        <td><input name="itens[{{ $k }}][valor]" value="{{ $i['valor'] !== '' ? Br::moeda($i['valor']) : '' }}" placeholder="0,00" style="width:120px;text-align:right" @readonly($ro)></td>
      </tr>
      @endforeach
    </table>
    <div class="grid" style="margin-top:12px">
      <div><label>IRRF descontado pela imobiliária (R$)</label><input name="irrf_imobiliaria" value="{{ $a->irrf_imobiliaria ? Br::moeda($a->irrf_imobiliaria) : '' }}" placeholder="0,00" @readonly($ro)></div>
      <div><label>Saldo do boleto no e-mail (R$, opcional)</label><input name="valor_boleto" value="{{ $a->valor_boleto ? Br::moeda($a->valor_boleto) : '' }}" placeholder="0,00" @readonly($ro)></div>
      <div><label>Data da nota (emissão/entrada)</label><input type="date" name="data_nota" value="{{ $a->data_nota ? \Illuminate\Support\Carbon::createFromFormat('d/m/Y', $a->data_nota)->format('Y-m-d') : '' }}" @readonly($ro)></div>
      <div><label>Vencimento do boleto</label><input type="date" name="vencimento" value="{{ $a->vencimento ? \Illuminate\Support\Carbon::createFromFormat('d/m/Y', $a->vencimento)->format('Y-m-d') : '' }}" @readonly($ro)></div>
      <div><label>Taxa de administração (%)</label><input name="perc_administracao" value="{{ Br::moeda($a->perc_administracao ?: 10) }}" @readonly($ro)></div>
      <div style="grid-column:1/-1"><label>Observações</label><input name="observacoes" value="{{ $a->observacoes }}" @readonly($ro)></div>
    </div>
    @unless ($ro)<p></p><button class="btn pri" name="acao" value="salvar">Salvar e calcular</button>@endunless
  </div>

  @if ($calc)
  <div class="card">
    <h2 style="margin-top:0">2. O que lançar na nota (Escrita Fiscal → Notas de Entrada)</h2>
    <p style="color:var(--mut);margin-top:0">Fornecedor {{ Aluguel::config('aluguel_fornecedor') }} · Acumulador {{ Aluguel::config('aluguel_acumulador') }} ·
      CFOP {{ Aluguel::config('aluguel_cfop') }} · Documento {{ $a->documento() }} · {{ Aluguel::config('aluguel_imposto') }} cód. {{ Aluguel::config('aluguel_cod_recolhimento') }}</p>
    <div class="res">
      <div><span>Valor Produtos (aluguéis)</span><b>{{ Br::moeda($calc['valor_produtos']) }}</b></div>
      <div><span>Seguro</span><b>{{ Br::moeda($calc['seguro']) }}</b></div>
      <div><span>Valor Contábil</span><b>{{ Br::moeda($calc['valor_contabil']) }}</b></div>
      <div class="digitar"><span>Base de cálculo a digitar</span><b>{{ $calc['base'] !== null ? Br::moeda($calc['base']) : '—' }}</b></div>
      <div class="digitar"><span>IRRF (a Domínio vai calcular)</span><b>{{ $calc['irrf_conferido'] !== null ? Br::moeda($calc['irrf_conferido']) : '—' }}</b></div>
      <div><span>Boleto a pagar</span><b>{{ Br::moeda($calc['boleto']) }}</b>
        @if (abs($calc['boleto'] - ($calc['boleto_calculado'] ?? $calc['boleto'])) >= 0.01)<small style="color:var(--warn)">entradas − IRRF = {{ Br::moeda($calc['boleto_calculado']) }} (a imobiliária usou o IRRF sem arredondar)</small>@endif</div>
    </div>
    <p style="font-size:13px;margin-bottom:0">
      Conferência: base natural ({{ Br::moeda(100 - ($a->perc_administracao ?: 10)) }}% do aluguel) {{ Br::moeda($calc['base_natural']) }} → IRRF {{ Br::moeda($calc['irrf_natural']) }}
      @if ($calc['reducao_natural'] > 0)(com redução de 2026 de {{ Br::moeda($calc['reducao_natural']) }})@endif.
      @if (abs($calc['diferenca_irrf']) >= 0.01)
        <b style="color:var(--warn)">A imobiliária descontou {{ Br::moeda($calc['irrf']) }} ({{ $calc['diferenca_irrf'] > 0 ? 'a mais' : 'a menos' }}: {{ Br::moeda(abs($calc['diferenca_irrf'])) }});
        a base foi ajustada em {{ Br::moeda($calc['ajuste_base'] ?? 0) }} para a Domínio bater.</b>
      @else
        IRRF da imobiliária igual ao cálculo — sem ajuste.
      @endif
      @if ($calc['base'] === null)<br><b style="color:var(--err)">Nenhuma base dá exatamente esse IRRF na tabela — confira o valor digitado.</b>@endif
    </p>
    @if (in_array($a->status, ['rascunho', 'erro'], true))
    <p></p><button class="btn ok" name="acao" value="lancar" @disabled($calc['base'] === null)
      onclick="return confirm('Lançar a nota de {{ $a->competencia }} na Escrita Fiscal da Domínio? O EFD-Reinf NÃO será transmitido.')">Lançar na Domínio (teste, sem Reinf)</button>
    @endif
  </div>
  @endif
</form>

@if ($tarefa)<p style="color:var(--mut);font-size:13px">Robô: tarefa #{{ $tarefa->id }} — {{ $tarefa->status }} @if ($tarefa->mensagem)· {{ $tarefa->mensagem }}@endif</p>@endif

<div class="card"><h2 style="margin-top:0">Meses</h2>
  @forelse ($lista as $x)
    <a class="btn" style="margin:0 6px 6px 0" href="{{ route('alugueis.index', ['competencia' => $x->competencia]) }}">{{ $x->competencia }} · {{ Aluguel::STATUS[$x->status] ?? $x->status }}</a>
  @empty
    <p style="margin:0;color:var(--mut)">Nenhum ainda.</p>
  @endforelse
</div>
@endsection
