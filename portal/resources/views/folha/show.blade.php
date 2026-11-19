@extends('layouts.app')
@section('titulo', $f->calculo.' '.$f->competencia)
@push('head')<style>
  .kpis { display:grid; grid-template-columns:repeat(auto-fill, minmax(170px, 1fr)); gap:12px; margin-bottom:16px; }
  .kpi { background:var(--card); border:1px solid var(--bd); border-radius:8px; padding:12px 14px; }
  .kpi span { display:block; font-size:12px; color:var(--mut); } .kpi b { font-size:20px; }
  .kpi.destaque { border-color:var(--pri); } .kpi.destaque b { color:var(--pri); }
  details > summary { cursor:pointer; list-style:none; } details > summary::-webkit-details-marker { display:none; }
  details[open] > summary { background:#f3f6fb; }
  .func { display:grid; grid-template-columns:50px minmax(180px,2fr) minmax(140px,1.2fr) repeat(4, minmax(95px,.8fr)); gap:8px; padding:9px 10px; border-bottom:1px solid var(--bd); align-items:center; }
  .func.cab { font-size:13px; color:var(--mut); font-weight:600; } .func .n { text-align:right; }
  .eventos { padding:6px 16px 14px 60px; display:grid; grid-template-columns:1fr 1fr; gap:4px 30px; font-size:13px; border-bottom:1px solid var(--bd); }
  .eventos table td { padding:3px 6px; border:0; } .eventos h4 { margin:8px 0 4px; font-size:13px; }
  .cons { color:var(--warn); font-weight:600; }
  .abas { display:flex; gap:8px; margin-bottom:10px; flex-wrap:wrap; } .abas .ativo { background:var(--pri); color:#fff; }
  iframe.pdf { width:100%; height:80vh; border:1px solid var(--bd); border-radius:6px; background:#fff; }
  @media (max-width:800px) { .func { grid-template-columns:40px 1fr 90px; } .func .opc { display:none; } .eventos { grid-template-columns:1fr; padding-left:16px; } }
</style>@endpush
@section('conteudo')
@php use App\Support\Br; @endphp
<p><a href="{{ route('folha.index') }}">← Folhas</a></p>
<h1>{{ $f->calculo }} {{ $f->competencia }} — {{ $f->empresa_codigo }} - {{ $f->empresa_nome }}</h1>
<p style="color:var(--mut);margin-top:-8px">CNPJ {{ $f->cnpj }} · importado em {{ $f->updated_at->format('d/m/Y H:i') }} por {{ $f->importado_por }} ({{ $f->origem }})</p>
@unless ($d['confere'])<div class="msg erro">A soma dos líquidos não bate com o Líquido Geral do relatório. Confira o PDF.</div>@endunless

<div class="kpis">
  <div class="kpi destaque"><span>Líquido a pagar</span><b>R$ {{ Br::moeda($d['total_liquido']) }}</b></div>
  @if ($f->vencimento())<div class="kpi destaque"><span>Vencimento</span><b>{{ $f->vencimento() }}</b></div>@endif
  <div class="kpi"><span>Proventos</span><b>R$ {{ Br::moeda($d['total_proventos']) }}</b></div>
  <div class="kpi"><span>Descontos</span><b>R$ {{ Br::moeda($d['total_descontos']) }}</b></div>
  <div class="kpi"><span>Consignados</span><b>R$ {{ Br::moeda($d['total_consignado'] ?? 0) }}</b></div>
  <div class="kpi"><span>INSS (guia)</span><b>R$ {{ Br::moeda($d['total_inss']) }}</b></div>
  <div class="kpi"><span>FGTS</span><b>R$ {{ Br::moeda($d['total_fgts']) }}</b></div>
  <div class="kpi"><span>IRRF</span><b>R$ {{ Br::moeda($d['total_irrf']) }}</b></div>
</div>

@if (isset($d['fixos_removidos']))
<div class="card">
  <h2 style="margin-top:0">Valores fixos de adiantamento removidos antes do cálculo</h2>
  @forelse ($d['fixos_removidos'] as $r)
    @if ($loop->first)<table><tr><th>Cód.</th><th>Funcionário</th><th class="n">Valor removido</th></tr>@endif
    <tr><td>{{ $r['codigo'] }}</td><td>{{ $r['nome'] }}</td><td class="n">R$ {{ $r['valor_anterior'] }}</td></tr>
    @if ($loop->last)</table>@endif
  @empty
    <p style="margin:0">Nenhum funcionário tinha valor fixo.</p>
  @endforelse
</div>
@endif

<div class="card">
  <h2 style="margin-top:0">Resumo por funcionário <span style="font-weight:400;color:var(--mut);font-size:13px">— clique no nome para ver os eventos</span></h2>
  <div class="func cab"><span>Cód.</span><span>Funcionário</span><span class="opc">Cargo</span><span class="n opc">Proventos</span><span class="n opc">Descontos</span><span class="n opc">Consignado</span><span class="n">Líquido</span></div>
  @foreach (collect($d['funcionarios'])->sortBy('nome') as $x)
  <details>
    <summary class="func"><span>{{ $x['codigo'] }}</span><span><b>{{ $x['nome'] }}</b><br><small style="color:var(--mut)">CPF {{ $x['cpf'] }} · adm. {{ $x['admissao'] }}</small></span>
      <span class="opc">{{ $x['cargo'] }}</span><span class="n opc">{{ Br::moeda($x['proventos']) }}</span><span class="n opc">{{ Br::moeda($x['descontos']) }}</span>
      <span class="n opc {{ $x['consignado'] ? 'cons' : '' }}">{{ $x['consignado'] ? Br::moeda($x['consignado']) : '-' }}</span><span class="n"><b>{{ Br::moeda($x['liquido']) }}</b></span></summary>
    <div class="eventos">
      @foreach (['provento' => 'Proventos', 'desconto' => 'Descontos'] as $tipo => $rotulo)
      <div><h4>{{ $rotulo }}</h4><table>
        @forelse (collect($x['rubricas'])->where('tipo', $tipo) as $r)
          <tr class="{{ $r['consignado'] ? 'cons' : '' }}"><td>{{ $r['codigo'] }}</td><td>{{ $r['descricao'] }}{{ $r['consignado'] ? ' (consignado)' : '' }}</td>
            <td class="n" style="color:var(--mut)">{{ $r['referencia'] }}</td><td class="n">{{ Br::moeda($r['valor']) }}</td></tr>
        @empty
          <tr><td style="color:var(--mut)">nenhum</td></tr>
        @endforelse
      </table></div>
      @endforeach
      <div style="grid-column:1/-1;color:var(--mut)">Salário base {{ Br::moeda($x['salario']) }} · FGTS {{ Br::moeda($x['fgts']) }} · {{ $x['situacao'] }}</div>
    </div>
  </details>
  @endforeach
  <div class="func" style="background:#f0f2f5;font-weight:600"><span></span><span>Total ({{ count($d['funcionarios']) }})</span><span class="opc"></span>
    <span class="n opc">{{ Br::moeda($d['total_proventos']) }}</span><span class="n opc">{{ Br::moeda($d['total_descontos']) }}</span>
    <span class="n opc">{{ Br::moeda($d['total_consignado'] ?? 0) }}</span><span class="n">{{ Br::moeda($d['total_liquido']) }}</span></div>
</div>

@unless ($f->ehAdiantamento())
<div class="card">
  <h2 style="margin-top:0">eSocial da folha</h2>
  @if ($errors->has('esocial'))<div class="msg erro">{{ $errors->first('esocial') }}</div>@endif
  @php $esTag = ['transmitido' => 'verde', 'validado' => 'azul', 'na_fila' => 'azul', 'aguardando' => 'azul', 'fechando' => 'azul', 'erro' => 'vermelha']; $esOcupado = in_array($f->esocial_status, ['na_fila', 'aguardando', 'fechando'], true); @endphp
  <p style="margin-top:0">
    @if ($f->esocial_status)<span class="tag {{ $esTag[$f->esocial_status] ?? '' }}">{{ \App\Services\EsocialStatusFolha::ROTULOS[$f->esocial_status] ?? $f->esocial_status }}</span>@endif
    {{ $f->esocial_mensagem ?? 'Ainda não transmitido pelo portal. O robô envia S-1200 (remunerações), S-1210 (pagamentos) e o fechamento S-1299 na Domínio; a DCTFWeb continua com você.' }}
    @if ($f->esocial_transmitido_em)<br><small style="color:var(--mut)">Transmitido em {{ $f->esocial_transmitido_em->format('d/m/Y H:i') }} por {{ $f->esocial_transmitido_por }}.</small>@endif
    @if ($f->esocial_conferido_em)<small style="color:var(--mut)"> Conferido em {{ $f->esocial_conferido_em->format('d/m/Y H:i') }}.</small>@endif
    @if ($f->esocial_fechado_em)<small style="color:var(--mut)"> Fechamento validado em {{ $f->esocial_fechado_em->format('d/m/Y H:i') }}.</small>@endif
  </p>
  <form method="post" action="{{ route('folha.esocial', $f) }}" style="display:flex;gap:10px;flex-wrap:wrap">@csrf
    @if (! $esOcupado && $f->esocial_status !== 'transmitido')
      <button class="btn ok" name="acao" value="transmitir"
        onclick="return confirm('Transmitir o eSocial da folha {{ $f->competencia }}? O robô envia S-1200 e S-1210, confere até validar e então envia o fechamento S-1299. Vai para o governo e não dá para desfazer pelo portal. A DCTFWeb NÃO será transmitida.')">{{ $f->esocial_status === 'validado' ? 'Enviar fechamento (S-1299)' : 'Transmitir eSocial' }}</button>
    @endif
    @if (! $esOcupado)<button class="btn" name="acao" value="conferir">{{ $f->esocial_conferido_em ? 'Conferir de novo' : 'Só conferir status' }}</button>@endif
  </form>
  @if ($f->esocial_empregados)
    <table style="margin-top:10px"><tr><th>Empregado</th><th>Remuneração (S-1200)</th><th>Pagamento (S-1210)</th></tr>
      @foreach ($f->esocial_empregados as $e)
        <tr><td>{{ $e['nome'] }}</td>
          @foreach (['remuneracao', 'pagamento'] as $k)
            <td>@if ($e[$k] === '')<span style="color:var(--mut)">—</span>@else<span class="tag {{ \App\Services\EsocialStatusFolha::validado($e[$k]) ? 'verde' : 'vermelha' }}">{{ $e[$k] }}</span>@endif</td>
          @endforeach</tr>
      @endforeach
    </table>
  @endif
</div>
@endunless

<div class="card">
  <h2 style="margin-top:0">Conta Azul</h2>
  @if (session('mensagem'))<div class="msg ok">{{ session('mensagem') }}</div>@endif
  @if ($f->contaazul_id)
    <p style="margin:0"><span class="tag verde">Lançado</span> Conta a pagar de <b>R$ {{ Br::moeda($f->contaazul_valor) }}</b>
      com vencimento <b>{{ $f->contaazul_vencimento }}</b>, enviada por {{ $f->contaazul_enviado_por }} em {{ $f->contaazul_enviado_em?->format('d/m/Y H:i') }}.</p>
    @if (round($f->contaazul_valor, 2) !== round((float) $d['total_liquido'], 2))
      <div class="msg erro" style="margin:10px 0 0">A folha foi reimportada e o líquido mudou (agora R$ {{ Br::moeda($d['total_liquido']) }}). Ajuste o valor direto no Conta Azul.</div>
    @endif
  @elseif (! $caConectado)
    <p style="margin:0;color:var(--mut)">Conta Azul ainda não conectado. <a href="{{ route('contaazul') }}">Configurar</a></p>
  @else
    <form method="post" action="{{ route('folha.contaazul', $f) }}" style="display:flex;gap:10px;align-items:end;flex-wrap:wrap"
          onsubmit="const b=this.querySelector('button');b.disabled=true;b.innerText='Enviando...';return confirm('Criar no Conta Azul a conta a pagar de R$ {{ Br::moeda($d['total_liquido']) }}?')">@csrf
      <div><label>Descrição</label><input value="{{ $f->descricaoContaAzul() }}" disabled style="min-width:280px"></div>
      <div><label>Valor (líquido total)</label><input value="R$ {{ Br::moeda($d['total_liquido']) }}" disabled></div>
      <div><label>Vencimento</label><input type="date" name="vencimento" required
        value="{{ \Carbon\Carbon::createFromFormat('d/m/Y', $f->vencimentoPagamento())->format('Y-m-d') }}"></div>
      <button class="btn ok" @disabled($caPendencias)>Enviar para o Conta Azul</button>
    </form>
    @if ($caPendencias)<p class="dica" style="color:var(--err)">Falta escolher em <a href="{{ route('contaazul') }}">Conta Azul</a>: {{ implode(', ', $caPendencias) }}.</p>
    @else<p class="dica" style="color:var(--mut)">Fornecedor: {{ \App\Models\Configuracao::ler('contaazul_fornecedor_nome', '—') }}. O portal não lança a mesma folha duas vezes.</p>@endif
  @endif
</div>

<div class="card">
  <h2 style="margin-top:0">PDFs</h2>
  <div class="abas">
    <a class="btn ativo" href="{{ route('folha.pdf', [$f, 'liquidos']) }}" target="visor" onclick="ativa(this)">Relação de líquidos</a>
    @if ($f->temArquivo('extrato'))<a class="btn" href="{{ route('folha.pdf', [$f, 'extrato']) }}" target="visor" onclick="ativa(this)">Extrato da Domínio</a>@endif
    @if ($f->temArquivo('liquidos_dominio'))<a class="btn" href="{{ route('folha.pdf', [$f, 'liquidos_dominio']) }}" target="visor" onclick="ativa(this)">Líquidos da Domínio</a>@endif
    <a class="btn" href="{{ route('folha.pdf', [$f, 'liquidos', 'baixar' => 1]) }}">Baixar líquidos</a>
  </div>
  <iframe class="pdf" name="visor" src="{{ route('folha.pdf', [$f, 'liquidos']) }}"></iframe>
</div>
<script>function ativa(el){document.querySelectorAll('.abas a[target]').forEach(a=>a.classList.remove('ativo'));el.classList.add('ativo');}</script>
@endsection
