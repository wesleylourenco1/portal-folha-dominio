@extends('layouts.app')
@section('titulo', 'Admissões')
@push('head')<meta http-equiv="refresh" content="20">@endpush
@section('conteudo')
<div style="display:flex;justify-content:space-between;align-items:center">
  <h1>Admissões</h1>
  <a class="btn pri" href="{{ route('admissoes.create') }}">+ Nova admissão</a>
</div>
<div class="card" style="overflow-x:auto">
<table>
  <tr><th>#</th><th>Nome</th><th>CPF</th><th>Empresa</th><th>Admissão</th><th>Status</th><th>Atualizado</th></tr>
  @forelse ($admissoes as $a)
  <tr>
    <td><a href="{{ route('admissoes.show', $a) }}">{{ $a->id }}</a></td>
    <td><a href="{{ route('admissoes.show', $a) }}">{{ ($a->dados['nome'] ?? '') ?: '(lendo documentos...)' }}</a></td>
    <td>{{ $a->dados['cpf'] ?? '' }}</td>
    <td>{{ $a->dados['empresa_codigo'] ?? '' }}</td>
    <td>{{ $a->dados['data_admissao'] ?? '' }}</td>
    <td>@include('admissoes._status', ['status' => $a->status])</td>
    <td>{{ $a->updated_at->format('d/m/Y H:i') }}</td>
  </tr>
  @empty
  <tr><td colspan="7">Nenhuma admissão ainda.</td></tr>
  @endforelse
</table>
</div>
@endsection
