@extends('layouts.app')
@section('titulo', 'Nova admissão')
@section('conteudo')
<h1>Nova admissão</h1>
<form method="post" action="{{ route('admissoes.store') }}" enctype="multipart/form-data"
      onsubmit="const b=this.querySelector('button[type=submit]');b.disabled=true;b.innerText='Enviando...'">@csrf
  <div class="card">
    <h2 style="margin-top:0">1. Documentos do funcionário</h2>
    <p style="color:var(--mut);margin-top:0">RG ou CNH, CPF, CTPS (ou CTPS digital), título de eleitor, comprovante de residência,
      certidão de nascimento/casamento, PIS, reservista, dados bancários. PDF ou foto (JPG/PNG), vários de uma vez ou tudo num <b>.zip</b> (o portal abre e pega os documentos, inclusive de subpastas).</p>
    <input type="file" name="documentos[]" multiple required accept=".pdf,.jpg,.jpeg,.png,.webp,.zip">
  </div>
  <div class="card">
    <h2 style="margin-top:0">2. Dados do contrato (preenchidos pelo RH)</h2>
    <div style="max-width:460px;margin-bottom:14px"><label for="funcao">Função pré-definida</label>
      <select id="funcao">
        <option value="">— escolha para preencher cargo, CBO, salário e horário —</option>
        @foreach ($funcoes as $i => $f)
          <option value="{{ $i }}">{{ $f->nome }}@if ($f->salario) — R$ {{ \App\Support\Br::moeda($f->salario) }}@endif</option>
        @endforeach
      </select>
      @if ($funcoes->isEmpty())<div class="dica" style="color:var(--mut)">Nenhuma função cadastrada. <a href="{{ route('funcoes.index') }}">Cadastrar funções</a></div>@endif
    </div>
    <div class="grid">
      @foreach ($campos as [$secao, $campo, $rotulo, $origem])
        @continue($origem !== 'rh')
        <div><label for="{{ $campo }}">{{ $rotulo }}</label>
          @if ($campo === 'observacoes')
            <textarea name="{{ $campo }}" id="{{ $campo }}" rows="2">{{ old($campo) }}</textarea>
          @else
            <input name="{{ $campo }}" id="{{ $campo }}" value="{{ old($campo) }}"
              @if ($campo === 'data_admissao') placeholder="DD/MM/AAAA" @elseif ($campo === 'salario') placeholder="0,00" @endif>
          @endif
        </div>
      @endforeach
    </div>
  </div>
  <label style="display:flex;gap:6px;align-items:center;margin:0 0 12px;color:var(--tx)"><input type="checkbox" name="teste" value="1" style="width:auto"> Somente teste da leitura (não enviar para a Domínio)</label>
  <button class="btn pri" type="submit">Enviar e ler documentos</button>
</form>
@php
  $funcoesJs = $funcoes->map(fn ($f) => [
      'cargo_codigo' => $f->codigo, 'cargo' => $f->nome, 'cbo' => $f->cbo,
      'salario' => $f->salario ? \App\Support\Br::moeda($f->salario) : '', 'tipo_salario' => $f->tipo_salario,
      'horas_mes' => (string) $f->horas_mes, 'horario' => $f->horario, 'departamento' => $f->departamento])->values();
@endphp
<script>
  const funcoes = @json($funcoesJs);
  document.getElementById('funcao').addEventListener('change', e => {
    const f = funcoes[e.target.value];
    if (!f) return;
    for (const [campo, valor] of Object.entries(f)) {
      const el = document.getElementById(campo);
      if (el && valor) el.value = valor;
    }
  });
</script>
@endsection
