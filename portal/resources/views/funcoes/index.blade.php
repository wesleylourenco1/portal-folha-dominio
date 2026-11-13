@extends('layouts.app')
@section('titulo', 'Funções')
@section('conteudo')
@php use App\Support\Br; @endphp
<h1>Funções</h1>
<div class="card">
  <h2 style="margin-top:0">{{ $atual ? 'Editar função' : 'Nova função' }}</h2>
  <form method="post" action="{{ route('funcoes.salvar') }}">@csrf
    <input type="hidden" name="id" value="{{ $atual?->id }}">
    <div class="grid">
      <div><label>Código na Domínio</label><input name="codigo" value="{{ $atual?->codigo }}"></div>
      <div><label>Função / cargo</label><input name="nome" value="{{ $atual?->nome }}" required></div>
      <div><label>CBO</label><input name="cbo" value="{{ $atual?->cbo }}" placeholder="000000"></div>
      <div><label>Salário (R$)</label><input name="salario" value="{{ $atual?->salario ? Br::moeda($atual->salario) : '' }}" placeholder="0,00"></div>
      <div><label>Tipo de salário</label><select name="tipo_salario">
        @foreach (['Mensal', 'Horista', 'Diarista'] as $t)<option @selected($atual?->tipo_salario === $t)>{{ $t }}</option>@endforeach
      </select></div>
      <div><label>Horas/mês</label><input name="horas_mes" value="{{ $atual?->horas_mes ?? 220 }}"></div>
      <div><label>Horário de trabalho</label><input name="horario" value="{{ $atual?->horario }}" placeholder="08:00-12:00 13:00-17:48"></div>
      <div><label>Departamento</label><input name="departamento" value="{{ $atual?->departamento }}"></div>
    </div>
    <p></p>
    <button class="btn pri">Salvar</button> @if ($atual)<a class="btn" href="{{ route('funcoes.index') }}">Cancelar</a>@endif
  </form>
</div>
<div class="card" style="overflow-x:auto">
<table>
  <tr><th>Cód.</th><th>Função</th><th>CBO</th><th class="n">Salário</th><th>Tipo</th><th>Horas/mês</th><th>Horário</th><th>Depto</th><th></th></tr>
  @forelse ($funcoes as $f)
  <tr><td>{{ $f->codigo }}</td><td>{{ $f->nome }}</td><td>{{ $f->cbo }}</td><td class="n">{{ $f->salario ? Br::moeda($f->salario) : '' }}</td>
    <td>{{ $f->tipo_salario }}</td><td>{{ $f->horas_mes }}</td><td>{{ $f->horario }}</td><td>{{ $f->departamento }}</td>
    <td style="white-space:nowrap"><a class="btn" href="{{ route('funcoes.index', ['editar' => $f->id]) }}">Editar</a>
      <form method="post" action="{{ route('funcoes.excluir', $f) }}" style="display:inline" onsubmit="return confirm('Excluir esta função?')">@csrf
        <button class="btn">Excluir</button></form></td></tr>
  @empty
  <tr><td colspan="9">Nenhuma função cadastrada. Elas aparecem sozinhas quando uma folha é importada.</td></tr>
  @endforelse
</table>
</div>
@endsection
