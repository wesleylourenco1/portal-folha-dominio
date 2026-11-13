@php
  $cor = match (true) {
      in_array($status, ['concluido', 'aguardando_esocial']) => 'verde',
      $status === 'revisar' => 'amarela',
      str_starts_with($status, 'erro') => 'vermelha',
      default => 'azul',
  };
@endphp
<span class="tag {{ $cor }}">{{ \App\Models\Admissao::STATUS[$status] ?? $status }}</span>
