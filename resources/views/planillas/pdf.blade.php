<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }
  @page { size: letter landscape; margin: 0; }
  body { font-family: DejaVu Sans, sans-serif; font-size: 8px; color: #1e293b; }
  .page { padding: 25mm 30mm; }

  .header { text-align: center; margin-bottom: 10px; border-bottom: 2px solid #1d4ed8; padding-bottom: 6px; }
  .header h1 { font-size: 13px; color: #1d4ed8; font-weight: bold; }
  .header h2 { font-size: 11px; margin-top: 2px; }
  .header p  { font-size: 8px; color: #64748b; margin-top: 2px; }

  .meta { display: flex; gap: 20px; margin-bottom: 8px; font-size: 8px; }
  .meta span { color: #64748b; }
  .meta strong { color: #1e293b; }

  table { width: 100%; border-collapse: collapse; }
  thead tr { background-color: #1d4ed8; color: white; }
  thead th { padding: 4px 3px; text-align: center; font-size: 7px; font-weight: bold; }
  thead th:first-child { text-align: left; }

  tbody tr:nth-child(even) { background-color: #f8fafc; }
  tbody tr:nth-child(odd)  { background-color: #ffffff; }
  tbody td { padding: 3px; font-size: 7.5px; border-bottom: 1px solid #e2e8f0; }
  tbody td.num { text-align: right; }
  tbody td.emp { font-weight: 600; }

  .totals-row td { background-color: #1e40af; color: white; font-weight: bold; font-size: 7.5px; padding: 4px 3px; }
  .totals-row td.num { text-align: right; }

  .footer { margin-top: 30px; display: flex; justify-content: space-between; }
  .firma  { text-align: center; width: 30%; }
  .firma .linea { border-top: 1px solid #1e293b; padding-top: 4px; font-size: 8px; }

  .badge-activo  { background:#dcfce7; color:#166534; border-radius:3px; padding:1px 5px; }
  .badge-cerrado { background:#fee2e2; color:#991b1b; border-radius:3px; padding:1px 5px; }
</style>
</head>
<body>
<div class="page">

<div class="header">
  <h1>Hotel Palma Real</h1>
  <h2>PLANILLA DE PAGO — {{ strtoupper($planilla->nombre_planilla) }}</h2>
  <p>
    Tipo: {{ $planilla->tipo_planilla }} &nbsp;|&nbsp;
    Fecha: {{ \Carbon\Carbon::parse($planilla->fecha_generada)->format('d/m/Y') }} &nbsp;|&nbsp;
    Estado: <span class="{{ $planilla->estado === 'Activo' ? 'badge-activo' : 'badge-cerrado' }}">{{ $planilla->estado }}</span> &nbsp;|&nbsp;
    Empleados: {{ $planilla->detalles->count() }}
  </p>
</div>

<table>
  <thead>
    <tr>
      <th style="width:14%">Empleado</th>
      <th style="width:8%">Depto.</th>
      <th style="width:3%">Días</th>
      <th style="width:7%">Sal. Base</th>
      <th style="width:6%">Otros Ing.</th>
      <th style="width:5%">IHSS</th>
      <th style="width:5%">RAP</th>
      <th style="width:5%">ISR</th>
      <th style="width:5%">Crefisa</th>
      <th style="width:5%">Transp.</th>
      <th style="width:5%">Radios</th>
      <th style="width:5%">Uniforme</th>
      <th style="width:5%">Garden</th>
      <th style="width:6%">Otras Ded.</th>
      <th style="width:6%">Ded. Neta</th>
      <th style="width:7%">Sal. Neto</th>
      <th style="width:8%">Cuenta</th>
    </tr>
  </thead>
  <tbody>
    @foreach($planilla->detalles as $d)
    <tr>
      <td class="emp">{{ $d->empleado->apellidos }}, {{ $d->empleado->nombres }}</td>
      <td>{{ $d->departamento }}</td>
      <td class="num">{{ $d->dias_trabajados }}</td>
      <td class="num">{{ number_format($d->salario_base, 2) }}</td>
      <td class="num">{{ number_format($d->otros_ingresos, 2) }}</td>
      <td class="num">{{ number_format($d->ihss, 2) }}</td>
      <td class="num">{{ number_format($d->retencion_ahorro, 2) }}</td>
      <td class="num">{{ number_format($d->isr, 2) }}</td>
      <td class="num">{{ number_format($d->crefisa, 2) }}</td>
      <td class="num">{{ number_format($d->transporte, 2) }}</td>
      <td class="num">{{ number_format($d->radios, 2) }}</td>
      <td class="num">{{ number_format($d->uniforme, 2) }}</td>
      <td class="num">{{ number_format($d->garden, 2) }}</td>
      <td class="num">{{ number_format($d->otras_deducciones, 2) }}</td>
      <td class="num" style="color:#dc2626">{{ number_format($d->deduccion_neta, 2) }}</td>
      <td class="num" style="font-weight:bold">{{ number_format($d->salario_neto, 2) }}</td>
      <td style="font-family:monospace">{{ $d->cuenta_banco ?? '—' }}</td>
    </tr>
    @endforeach
  </tbody>
  <tr class="totals-row">
    <td colspan="3" style="text-align:left; padding-left:4px;">TOTALES</td>
    <td class="num">{{ number_format($totales['salario_base'], 2) }}</td>
    <td class="num">{{ number_format($totales['otros_ingresos'], 2) }}</td>
    <td class="num">{{ number_format($totales['ihss'], 2) }}</td>
    <td class="num">{{ number_format($totales['retencion_ahorro'], 2) }}</td>
    <td class="num">{{ number_format($totales['isr'], 2) }}</td>
    <td class="num">{{ number_format($totales['crefisa'], 2) }}</td>
    <td class="num">{{ number_format($totales['transporte'], 2) }}</td>
    <td class="num">{{ number_format($totales['radios'], 2) }}</td>
    <td class="num">{{ number_format($totales['uniforme'], 2) }}</td>
    <td class="num">{{ number_format($totales['garden'], 2) }}</td>
    <td class="num">{{ number_format($totales['otras_deducciones'], 2) }}</td>
    <td class="num">{{ number_format($totales['deduccion_neta'], 2) }}</td>
    <td class="num">{{ number_format($totales['salario_neto'], 2) }}</td>
    <td></td>
  </tr>
</table>

<div class="footer">
  <div class="firma">
    <div class="linea">Elaborado por</div>
  </div>
  <div class="firma">
    <div class="linea">Revisado por</div>
  </div>
  <div class="firma">
    <div class="linea">Autorizado por</div>
  </div>
</div>

</div>
</body>
</html>
