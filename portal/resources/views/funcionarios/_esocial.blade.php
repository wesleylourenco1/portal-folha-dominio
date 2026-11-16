{{-- Status do funcionário no eSocial (consultado pelo robô com o certificado da empresa) --}}
@php
  $tags = ['recebido' => ['verde', 'Recebido'], 'aguardando' => ['amarela', 'Admissão não encontrada ainda'],
           'sem_eventos' => ['amarela', 'Sem eventos nos últimos 30 dias'], 'erro' => ['vermelha', 'Erro na consulta']];
  [$cor, $texto] = $tags[$f->esocial_situacao] ?? ['', 'Não consultado'];
@endphp
@if ($f->esocial_admissao_ok)
  <span class="tag verde" title="Recibo {{ $f->esocial_recibo_admissao }}">Admissão aceita</span>
  <br><small style="color:var(--mut)">recibo {{ $f->esocial_recibo_admissao }}</small>
@else
  <span class="tag {{ $cor }}" @if ($f->esocial_mensagem) title="{{ $f->esocial_mensagem }}" @endif>{{ $texto }}</span>
@endif
@if ($f->esocial_situacao === 'recebido' && ! $f->esocial_admissao_ok)
  <br><small style="color:var(--mut)">{{ $f->esocial_eventos }} evento(s) · último recibo {{ $f->esocial_recibo }}</small>
@endif
@if ($f->esocial_consultado_em)<br><small style="color:var(--mut)">consultado em {{ $f->esocial_consultado_em->format('d/m H:i') }}</small>@endif
@if ($esocialAtivo && $f->ativo && $f->cpf)
  <form method="post" action="{{ route('funcionarios.esocial', $f) }}" style="margin-top:4px">@csrf
    <button class="btn" style="padding:2px 8px;font-size:12px" @disabled(! $esocialSaldo)>Consultar agora</button></form>
@endif
