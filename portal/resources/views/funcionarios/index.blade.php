@extends('layouts.app')
@section('titulo', 'Funcionários')
@push('head')<style>
  input.mini { width:100%; min-width:70px; padding:4px 6px; font-size:13px; }
  tr.inativo td { color:var(--mut); }
</style>@endpush
@section('conteudo')
@php use App\Support\Br; @endphp
<div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px">
  <h1>Funcionários {{ $todos ? '' : 'ativos' }} ({{ $lista->count() }})</h1>
  <a class="btn" href="{{ route('funcionarios.index', $todos ? [] : ['todos' => 1]) }}">{{ $todos ? 'Só ativos' : 'Mostrar inativos' }}</a>
</div>
<p style="color:var(--mut);margin-top:-6px">Atualizado automaticamente com a Domínio a cada folha/adiantamento importado.
  Local, VT, VR e PIX são do escritório (não vêm da Domínio) — edite e clique em Salvar na linha.</p>
@if (session('msg'))<div class="msg ok">{{ session('msg') }}</div>@endif
<div class="card" style="overflow-x:auto">
<table>
  <tr><th>Cód.</th><th>Nome</th><th>Cargo (Domínio)</th><th>Admissão</th><th class="n">Salário</th>
      <th>Local</th><th>VT</th><th>VR mês</th><th>Desc. VR</th><th>Comissão</th><th>Chave PIX</th><th></th></tr>
  @forelse ($lista as $f)
  @php $form = 'f'.$f->id; @endphp
  <tr class="{{ $f->ativo ? '' : 'inativo' }}">
    <td>{{ $f->codigo }}</td>
    <td><b>{{ $f->nome }}</b><br><small style="color:var(--mut)">{{ $f->cpf }}{{ $f->ativo ? '' : ' · '.$f->situacao }}</small></td>
    <td>{{ $f->cargo }}@if ($f->cargo_codigo) <small style="color:var(--mut)">({{ $f->cargo_codigo }})</small>@endif</td>
    <td>{{ $f->admissao }}</td>
    <td class="n">{{ $f->salario ? Br::moeda($f->salario) : '' }}</td>
    <td><input form="{{ $form }}" class="mini" name="local" value="{{ $f->local }}"></td>
    <td><input form="{{ $form }}" class="mini" name="vale_transporte" value="{{ $f->vale_transporte ? Br::moeda($f->vale_transporte) : '' }}"></td>
    <td><input form="{{ $form }}" class="mini" name="vr_mes" value="{{ $f->vr_mes ? Br::moeda($f->vr_mes) : '' }}"></td>
    <td><input form="{{ $form }}" class="mini" name="desconto_vr" value="{{ $f->desconto_vr ? Br::moeda($f->desconto_vr) : '' }}"></td>
    <td style="text-align:center"><input form="{{ $form }}" type="checkbox" name="comissionado" value="1" style="width:auto" @checked($f->comissionado)></td>
    <td><input form="{{ $form }}" class="mini" name="chave_pix" value="{{ $f->chave_pix }}" style="min-width:140px"></td>
    <td><form id="{{ $form }}" method="post" action="{{ route('funcionarios.update', $f) }}">@csrf<button class="btn" style="padding:5px 10px">Salvar</button></form></td>
  </tr>
  @empty
  <tr><td colspan="12">Nenhum funcionário ainda. Importe uma folha ou adiantamento.</td></tr>
  @endforelse
</table>
</div>
@endsection
