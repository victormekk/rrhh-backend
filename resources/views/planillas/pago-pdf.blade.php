<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<style>
  /* :not(html):not(body) evita un bug de dompdf: si <body> recibe margin:0
     explicito y la pagina tiene una <table>, dompdf ignora el margen del
     @page y dibuja los fondos de la tabla de borde a borde de la hoja. */
  *:not(html):not(body) { box-sizing: border-box; margin: 0; padding: 0; }
  @page { size: letter portrait; margin: 1.27cm; }
  body { font-family: DejaVu Sans, sans-serif; font-size: 8px; color: #1e293b; }
  .page { padding: 0; }

  /* Marrón #3b2b16 y dorado #b9921a extraídos del logotipo oficial */
  .header { text-align: center; margin-bottom: 12px; border-bottom: 2px solid #3b2b16; padding-bottom: 8px; }
  .header img { height: 60px; margin-bottom: 6px; }
  .header h2 { font-size: 15px; margin-top: 2px; color: #3b2b16; font-weight: bold; letter-spacing: 0.4px; }
  .header p  { font-size: 8.5px; color: #8a6d10; margin-top: 3px; font-weight: 600; }

  table { width: 100%; border-collapse: collapse; table-layout: fixed; }
  thead tr { background-color: #3b2b16; color: white; }
  thead th { padding: 4px 3px; text-align: center; font-size: 7.5px; font-weight: bold; white-space: nowrap; }
  thead th:first-child { text-align: left; }

  tbody tr:nth-child(even) { background-color: #f8fafc; }
  tbody tr:nth-child(odd)  { background-color: #ffffff; }
  tbody td { padding: 3px; font-size: 8px; border-bottom: 1px solid #e2e8f0; overflow-wrap: break-word; word-break: break-word; }
  tbody td.num { text-align: right; white-space: nowrap; }
  tbody td.emp { font-weight: 600; }

  .totals-row td { background-color: #b9921a; color: #3b2b16; font-weight: bold; font-size: 7px; padding: 4px 3px; }
  .totals-row td.num { text-align: right; }

  .subtotal-row td { font-size: 7px; }

  table.footer { width: 100%; border-collapse: collapse; margin-top: 1.8cm; }
  table.footer td { text-align: center; width: 33.33%; padding: 0 14px; border: none; background: transparent; }
  table.footer .linea { border-top: 1px solid #1e293b; padding-top: 4px; font-size: 8px; }
</style>
</head>
<body>
<div class="page">

<div class="header">
  <img src="{{ public_path('images/hpr_logo.png') }}" alt="Hotel y Villas Palma Real">
  <h2>{{ $titulo }} — {{ strtoupper($planilla->nombre_planilla) }}</h2>
  <p>
    N° {{ $correlativo }} &nbsp;|&nbsp;
    Fecha: {{ \Carbon\Carbon::parse($planilla->fecha_generada)->format('d/m/Y') }} &nbsp;|&nbsp;
    Empleados: {{ $detalles->count() }}
  </p>
</div>

<table>
  <thead>
    <tr>
      <th style="width:30%">Empleado</th>
      <th style="width:12%">H. Extra</th>
      <th style="width:14%">Otros Ing.</th>
      <th style="width:11%">IHSS</th>
      <th style="width:13%">Otras Ded.</th>
      <th style="width:10%">Ded. Neta</th>
      <th style="width:10%">Sal. Neto</th>
    </tr>
  </thead>
  <tbody>
    @foreach($detalles->groupBy('departamento') as $departamento => $filas)
    <tr>
      <td colspan="7" style="background-color:#f8f2df; color:#3b2b16; font-weight:bold; padding:4px;">{{ $departamento }}</td>
    </tr>
    @foreach($filas as $d)
    <tr>
      <td class="emp">{{ $d->empleado->nombres }} {{ $d->empleado->apellidos }}</td>
      <td class="num">{{ number_format($d->monto_horas_extras, 2) }}</td>
      <td class="num">{{ number_format($d->otros_ingresos, 2) }}</td>
      <td class="num">{{ number_format($d->ihss, 2) }}</td>
      <td class="num">{{ number_format($d->otras_deducciones, 2) }}</td>
      <td class="num" style="color:#dc2626">{{ number_format($d->deduccion_neta, 2) }}</td>
      <td class="num" style="font-weight:bold">{{ number_format($d->salario_neto, 2) }}</td>
    </tr>
    @endforeach
    <tr class="subtotal-row" style="background-color:#eee3c3; font-weight:bold;">
      <td style="padding:3px;">SUBTOTAL: {{ $departamento }}</td>
      <td class="num">{{ number_format($filas->sum('monto_horas_extras'), 2) }}</td>
      <td class="num">{{ number_format($filas->sum('otros_ingresos'), 2) }}</td>
      <td class="num">{{ number_format($filas->sum('ihss'), 2) }}</td>
      <td class="num">{{ number_format($filas->sum('otras_deducciones'), 2) }}</td>
      <td class="num">{{ number_format($filas->sum('deduccion_neta'), 2) }}</td>
      <td class="num">{{ number_format($filas->sum('salario_neto'), 2) }}</td>
    </tr>
    @endforeach
  </tbody>
  <tr class="totals-row">
    <td style="text-align:left; padding-left:4px;">TOTAL GENERAL</td>
    <td class="num">{{ number_format($totales['monto_horas_extras'], 2) }}</td>
    <td class="num">{{ number_format($totales['otros_ingresos'], 2) }}</td>
    <td class="num">{{ number_format($totales['ihss'], 2) }}</td>
    <td class="num">{{ number_format($totales['otras_deducciones'], 2) }}</td>
    <td class="num">{{ number_format($totales['deduccion_neta'], 2) }}</td>
    <td class="num">{{ number_format($totales['salario_neto'], 2) }}</td>
  </tr>
</table>

<table class="footer">
  <tr>
    <td><div class="linea">Elaborado por</div></td>
    <td><div class="linea">Revisado por</div></td>
    <td><div class="linea">Autorizado por</div></td>
  </tr>
</table>

</div>
</body>
</html>
