<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }
  @page { size: letter landscape; margin: 0; }
  body { font-family: DejaVu Sans, sans-serif; font-size: 8px; color: #1e293b; }
  .page { padding: 25mm 30mm; }

  .header { text-align: center; margin-bottom: 8px; padding-bottom: 6px; border-bottom: 2px solid #1d4ed8; }
  .header h1 { font-size: 13px; color: #1d4ed8; font-weight: bold; }
  .header h2 { font-size: 10px; margin-top: 2px; color: #1e293b; }
  .header p  { font-size: 7.5px; color: #64748b; margin-top: 2px; }

  .meta { display: flex; gap: 24px; margin-bottom: 8px; font-size: 8px; padding: 5px 8px; background: #f8fafc; border-radius: 4px; }
  .meta-item span { color: #64748b; }
  .meta-item strong { color: #1e293b; }

  table { width: 100%; border-collapse: collapse; }
  thead tr { background-color: #1d4ed8; color: white; }
  thead th { padding: 4px 4px; text-align: left; font-size: 7.5px; font-weight: bold; white-space: nowrap; }
  thead th.num { text-align: right; }
  thead th.ctr { text-align: center; }

  tbody tr:nth-child(even) { background-color: #f8fafc; }
  tbody tr:nth-child(odd)  { background-color: #ffffff; }
  tbody td { padding: 3px 4px; font-size: 8px; border-bottom: 1px solid #e2e8f0; }
  tbody td.num  { text-align: right; }
  tbody td.ctr  { text-align: center; }
  tbody td.emp  { font-weight: 600; }
  tbody td.dept { color: #64748b; font-size: 7.5px; }

  .badge-fijos  { background:#dbeafe; color:#1e40af; border-radius:3px; padding:1px 4px; font-size:7px; }
  .badge-extras { background:#fef3c7; color:#92400e; border-radius:3px; padding:1px 4px; font-size:7px; }

  .totals-row td { background-color: #1e40af; color: white; font-weight: bold; font-size: 8px; padding: 4px; }
  .totals-row td.num { text-align: right; }
  .totals-row td.ctr { text-align: center; }

  .footer { margin-top: 12px; font-size: 7px; color: #94a3b8; text-align: right; }
</style>
</head>
<body>
<div class="page">

  <div class="header">
    <h1>Hotel Palma Real</h1>
    <h2>ESTADÍSTICA LABORAL</h2>
    <p>Días trabajados y salario neto devengado por empleado</p>
  </div>

  <div class="meta">
    <div class="meta-item">
      <span>Período: </span>
      <strong>
        @if($periodo['inicio'] || $periodo['fin'])
          {{ $periodo['inicio'] ? \Carbon\Carbon::parse($periodo['inicio'])->format('d/m/Y') : '…' }}
          —
          {{ $periodo['fin'] ? \Carbon\Carbon::parse($periodo['fin'])->format('d/m/Y') : '…' }}
        @else
          Todos los períodos
        @endif
      </strong>
    </div>
    @if($periodo['search'])
    <div class="meta-item">
      <span>Empleado: </span>
      <strong>{{ $periodo['search'] }}</strong>
    </div>
    @endif
    <div class="meta-item">
      <span>Total empleados: </span>
      <strong>{{ $totales->total_empleados }}</strong>
    </div>
    <div class="meta-item">
      <span>Total días: </span>
      <strong>{{ number_format($totales->total_dias, 0, '.', ',') }}</strong>
    </div>
    <div class="meta-item">
      <span>Generado: </span>
      <strong>{{ now()->format('d/m/Y H:i') }}</strong>
    </div>
  </div>

  <table>
    <thead>
      <tr>
        <th style="width:20%">Empleado</th>
        <th style="width:12%">Departamento</th>
        <th class="ctr" style="width:7%">Quincenas</th>
        <th class="ctr" style="width:7%">Días trab.</th>
        <th class="num" style="width:13%">Salario base</th>
        <th class="num" style="width:10%">Otros ing.</th>
        <th class="num" style="width:13%">Deducciones</th>
        <th class="num" style="width:14%">Salario neto</th>
      </tr>
    </thead>
    <tbody>
      @foreach($rows as $row)
      <tr>
        <td class="emp">{{ $row->apellidos }}, {{ $row->nombres }}</td>
        <td class="dept">{{ $row->departamento }}</td>
        <td class="ctr">{{ $row->total_quincenas }}</td>
        <td class="ctr"><strong>{{ number_format($row->total_dias, 0) }}</strong></td>
        <td class="num">L. {{ number_format($row->total_salario_base, 2) }}</td>
        <td class="num">{{ $row->total_otros_ingresos > 0 ? 'L. '.number_format($row->total_otros_ingresos, 2) : '—' }}</td>
        <td class="num" style="color:#dc2626">– L. {{ number_format($row->total_deducciones, 2) }}</td>
        <td class="num" style="font-weight:bold; color:#166534">L. {{ number_format($row->total_salario_neto, 2) }}</td>
      </tr>
      @endforeach
    </tbody>
    <tfoot>
      <tr class="totals-row">
        <td colspan="3"><strong>TOTALES GENERALES</strong></td>
        <td class="ctr"><strong>{{ number_format($totales->total_dias, 0) }}</strong></td>
        <td class="num">L. {{ number_format($totales->total_salario_base, 2) }}</td>
        <td class="num">L. {{ number_format($totales->total_otros_ingresos, 2) }}</td>
        <td class="num">– L. {{ number_format($totales->total_deducciones, 2) }}</td>
        <td class="num">L. {{ number_format($totales->total_salario_neto, 2) }}</td>
      </tr>
    </tfoot>
  </table>

  <div class="footer">
    Documento generado el {{ now()->format('d/m/Y \a \l\a\s H:i') }} · Sistema RRHH Hotel Palma Real
  </div>

</div>
</body>
</html>
