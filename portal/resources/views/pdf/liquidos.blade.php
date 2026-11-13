<!doctype html>
<html lang="pt-BR"><head><meta charset="utf-8">
<style>
  body { font-family: DejaVu Sans, sans-serif; font-size: 9px; color:#1d2330; }
  h1 { font-size: 15px; margin: 0 0 4px; } p { margin: 0 0 10px; }
  table { width:100%; border-collapse: collapse; }
  th { background:#16325c; color:#fff; padding:5px; text-align:left; }
  td { padding:5px; border:0.5px solid #c8ced6; vertical-align: middle; }
  tr:nth-child(even) td { background:#f7f8fa; }
  .n { text-align:right; white-space:nowrap; } tfoot td { font-weight:bold; background:#eef1f5; }
</style></head><body>
@php use App\Support\Br; @endphp
<h1>Relação de líquidos — {{ $d['calculo'] }} — competência {{ $d['competencia'] }}</h1>
<p>{{ $d['empresa_codigo'] }} - {{ $d['empresa_nome'] }} · CNPJ {{ $d['cnpj'] }}@if ($folha->vencimento()) · <b>Vencimento {{ $folha->vencimento() }}</b>@endif</p>
<table>
  <thead><tr><th>Cód.</th><th>Funcionário</th><th>CPF</th><th>Cargo</th><th class="n">Proventos</th><th class="n">Descontos</th>
    <th class="n">Consignado</th><th class="n">Líquido</th><th style="width:22%">Assinatura</th></tr></thead>
  <tbody>
  @foreach ($funcionarios as $x)
    <tr><td>{{ $x['codigo'] }}</td><td>{{ $x['nome'] }}</td><td>{{ $x['cpf'] }}</td><td>{{ $x['cargo'] }}</td>
      <td class="n">{{ Br::moeda($x['proventos']) }}</td><td class="n">{{ Br::moeda($x['descontos']) }}</td>
      <td class="n">{{ $x['consignado'] ? Br::moeda($x['consignado']) : '-' }}</td><td class="n"><b>{{ Br::moeda($x['liquido']) }}</b></td><td></td></tr>
  @endforeach
  </tbody>
  <tfoot><tr><td></td><td>TOTAL ({{ count($funcionarios) }} funcionários)</td><td></td><td></td>
    <td class="n">{{ Br::moeda($d['total_proventos']) }}</td><td class="n">{{ Br::moeda($d['total_descontos']) }}</td>
    <td class="n">{{ Br::moeda($d['total_consignado'] ?? 0) }}</td><td class="n">{{ Br::moeda($d['total_liquido']) }}</td><td></td></tr></tfoot>
</table>
</body></html>
