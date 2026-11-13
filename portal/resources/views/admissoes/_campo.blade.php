{{-- Campo da ficha de admissão. Variáveis: $campo, $valor, $somenteLeitura (opcional) --}}
@php
    use App\Support\CamposAdmissao as C;
    $tipo = C::tipo($campo);
    $ro = $somenteLeitura ?? false;
@endphp
@if ($campo === 'observacoes')
  <textarea name="{{ $campo }}" id="{{ $campo }}" rows="2" @if ($ro) readonly @endif>{{ $valor }}</textarea>
@elseif ($tipo === 'lista')
  <select name="{{ $campo }}" id="{{ $campo }}" @if ($ro) disabled @endif>
    <option value="">— selecione —</option>
    @foreach (C::OPCOES[$campo] as $op)
      <option value="{{ $op }}" @selected(mb_strtoupper((string) $valor) === mb_strtoupper($op))>{{ $op }}</option>
    @endforeach
    @if ($valor && ! in_array(mb_strtoupper((string) $valor), array_map('mb_strtoupper', C::OPCOES[$campo])))
      <option value="{{ $valor }}" selected>{{ $valor }} (lido do documento — confira)</option>
    @endif
  </select>
  @if ($ro)<input type="hidden" name="{{ $campo }}" value="{{ $valor }}">@endif
@elseif ($tipo === 'data')
  <input type="date" name="{{ $campo }}" id="{{ $campo }}" value="{{ C::paraInput($campo, $valor) }}" @if ($ro) readonly @endif>
@elseif ($tipo === 'numero')
  <input type="number" name="{{ $campo }}" id="{{ $campo }}" value="{{ $valor }}" min="1" max="{{ C::EXPERIENCIA_MAX }}" step="1"
    inputmode="numeric" class="experiencia" @if ($ro) readonly @endif>
@else
  <input name="{{ $campo }}" id="{{ $campo }}" value="{{ $valor }}" @if ($ro) readonly @endif
    @if ($campo === 'salario') placeholder="0,00" @endif>
@endif
