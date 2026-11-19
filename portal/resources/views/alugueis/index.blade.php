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

@unless ($ro)
<form method="post" action="{{ route('alugueis.print') }}" enctype="multipart/form-data" class="card" id="form-print">@csrf
  <input type="hidden" name="competencia" value="{{ $a->competencia }}">
  <h2 style="margin-top:0">Enviar o print do e-mail da imobiliária</h2>
  <p style="color:var(--mut);margin-top:0">A IA lê as linhas, o IRRF, o saldo e o vencimento. Se a base bater com o IRRF e o saldo
    for entradas − IRRF, a nota vai sozinha para o robô (sem EFD-Reinf). Se algo não bater, os valores ficam aqui para você conferir.
    Dá para colar o print com <b>Ctrl+V</b> nesta página.</p>
  <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap">
    <input type="file" name="print[]" id="print" accept="image/png,image/jpeg,image/webp,application/pdf" multiple required>
    <label style="display:flex;gap:6px;align-items:center;margin:0"><input type="checkbox" name="lancar_sozinho" value="1" checked style="width:auto"> Lançar na Domínio sozinho se tudo bater</label>
    <button class="btn pri" id="btn-print">Ler print</button>
  </div>
  <p id="print-colado" style="font-size:13px;color:var(--mut);margin:6px 0 0"></p>
</form>
<script>
  document.getElementById('form-print').addEventListener('submit', () => { const b = document.getElementById('btn-print'); b.disabled = true; b.textContent = 'Lendo com a IA…'; });
  document.addEventListener('paste', (e) => {
    const imgs = [...(e.clipboardData?.files || [])].filter(f => f.type.startsWith('image/'));
    if (!imgs.length) return;
    const dt = new DataTransfer();
    imgs.forEach((f, i) => dt.items.add(new File([f], f.name && f.name !== 'image.png' ? f.name : `print_${i + 1}.png`, { type: f.type })));
    document.getElementById('print').files = dt.files;
    document.getElementById('print-colado').textContent = `${imgs.length} imagem(ns) colada(s). Clique em "Ler print".`;
  });
</script>
@endunless

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

@if ($a->exists)
<div class="card">
  <h2 style="margin-top:0">EFD-Reinf {{ $a->competencia }}</h2>
  @php $rTag = ['transmitido' => 'verde', 'na_fila' => 'azul', 'aguardando' => 'azul', 'erro' => 'vermelha', 'pendente' => 'amarela']; $rOcupado = in_array($a->reinf_status, ['na_fila', 'aguardando'], true); @endphp
  <p style="margin-top:0">
    @if ($a->reinf_status)<span class="tag {{ $rTag[$a->reinf_status] ?? '' }}">{{ \App\Services\ReinfStatus::ROTULOS[$a->reinf_status] ?? $a->reinf_status }}</span>@else<span class="tag amarela">Não transmitida</span>@endif
    {{ $a->reinf_mensagem ?? 'O robô faz a Entrega completa do mês na Escrita Fiscal (R-4010 do aluguel e fechamento R-4099) e confere o recibo. A DCTFWeb continua com você.' }}
    @if ($a->reinf_conferido_em)<br><small style="color:var(--mut)">Conferido em {{ $a->reinf_conferido_em->format('d/m/Y H:i') }}@if ($a->reinf_enviado_em) · transmitido em {{ $a->reinf_enviado_em->format('d/m/Y H:i') }} por {{ $a->reinf_enviado_por }}@endif.</small>@endif
  </p>
  <form method="post" action="{{ route('alugueis.reinf') }}" style="display:flex;gap:10px;flex-wrap:wrap">@csrf
    <input type="hidden" name="competencia" value="{{ $a->competencia }}">
    @if (! $rOcupado && $a->reinf_status !== 'transmitido' && $a->status === 'lancado')
      <button class="btn ok" name="acao" value="enviar"
        onclick="return confirm('Transmitir a EFD-Reinf de {{ $a->competencia }} (Entrega completa do mês: R-4010 e fechamento R-4099)? Vai para a Receita e não dá para desfazer pelo portal. A DCTFWeb NÃO será transmitida.')">Transmitir EFD-Reinf</button>
    @endif
    @unless ($rOcupado)<button class="btn" name="acao" value="conferir">{{ $a->reinf_conferido_em ? 'Conferir de novo' : 'Só conferir' }}</button>@endunless
  </form>
  @if ($a->reinf_eventos)
    <table style="margin-top:10px"><tr><th>Evento</th><th>Tipo</th><th>Situação</th><th>Recibo</th><th>Enviado</th></tr>
      @foreach ($a->reinf_eventos as $e)
        <tr><td>{{ $e['evento'] }} - {{ $e['descricao'] }}</td><td>{{ $e['tipo'] }}</td>
          <td><span class="tag {{ \App\Services\ReinfStatus::sucesso($e['situacao']) ? 'verde' : 'vermelha' }}">{{ $e['situacao'] }}</span></td>
          <td style="font-size:12px">{{ $e['recibo'] ?: '—' }}</td><td style="font-size:12px">{{ $e['enviado_em'] }}</td></tr>
      @endforeach
    </table>
  @endif
</div>
@endif

@if ($tarefa)<p style="color:var(--mut);font-size:13px">Robô: tarefa #{{ $tarefa->id }} — {{ $tarefa->status }} @if ($tarefa->mensagem)· {{ $tarefa->mensagem }}@endif</p>@endif

<div class="card"><h2 style="margin-top:0">Meses</h2>
  @forelse ($lista as $x)
    <a class="btn" style="margin:0 6px 6px 0" href="{{ route('alugueis.index', ['competencia' => $x->competencia]) }}">{{ $x->competencia }} · {{ Aluguel::STATUS[$x->status] ?? $x->status }} · Reinf: {{ \App\Services\ReinfStatus::ROTULOS[$x->reinf_status] ?? 'não transmitida' }}</a>
  @empty
    <p style="margin:0;color:var(--mut)">Nenhum ainda.</p>
  @endforelse
</div>
@endsection
