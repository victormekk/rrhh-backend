<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<style>
  /* Mismo estilo que planillas/pago-pdf. :not(html):not(body) evita un bug de
     dompdf que ignora el margen del @page cuando <body> tiene margin:0. */
  *:not(html):not(body) { box-sizing: border-box; margin: 0; padding: 0; }
  @page { size: letter portrait; margin: 1.27cm; }
  body { font-family: DejaVu Sans, sans-serif; font-size: 8px; color: #1e293b; }

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

  table.footer { width: 100%; border-collapse: collapse; margin-top: 1.8cm; }
  table.footer td { text-align: center; width: 33.33%; padding: 0 14px; border: none; background: transparent; }
  table.footer .linea { border-top: 1px solid #1e293b; padding-top: 4px; font-size: 8px; }
</style>
</head>
<body>

@php $columnas = $conCuenta ? 5 : 4; @endphp

<div class="header">
  <img src="{{ public_path('images/hpr_logo.png') }}" alt="Hotel y Villas Palma Real">
  <h2>{{ $titulo }} — {{ strtoupper($nombre) }}</h2>
  <p>
    N° {{ $correlativo }} &nbsp;|&nbsp;
    {{ $meta->concepto }} {{ $meta->tipo_aguinaldo }} &nbsp;|&nbsp;
    Corte: {{ $meta->fecha_corte ? \Carbon\Carbon::parse($meta->fecha_corte)->format('d/m/Y') : '—' }} &nbsp;|&nbsp;
    Empleados: {{ $filas->count() }}
  </p>
</div>

<table>
  <thead>
    <tr>
      <th style="width:{{ $conCuenta ? 36 : 52 }}%">Empleado</th>
      @if($conCuenta)<th style="width:18%">Cuenta</th>@endif
      <th style="width:16%">Monto</th>
      <th style="width:14%">Anticipos</th>
      <th style="width:16%">Total a Pagar</th>
    </tr>
  </thead>
  <tbody>
    @foreach($filas->groupBy('departamento') as $departamento => $grupo)
    <tr>
      <td colspan="{{ $columnas }}" style="background-color:#f8f2df; color:#3b2b16; font-weight:bold; padding:4px;">{{ $departamento }}</td>
    </tr>
    @foreach($grupo as $f)
    <tr>
      <td class="emp">{{ $f->empleado }}</td>
      @if($conCuenta)<td>{{ $f->cuenta }}</td>@endif
      <td class="num">{{ number_format($f->monto, 2) }}</td>
      <td class="num" style="color:#dc2626">{{ number_format($f->anticipos, 2) }}</td>
      <td class="num" style="font-weight:bold">{{ number_format($f->total, 2) }}</td>
    </tr>
    @endforeach
    <tr style="background-color:#eee3c3; font-weight:bold;">
      <td colspan="{{ $conCuenta ? 2 : 1 }}" style="padding:3px; font-size:7px;">SUBTOTAL: {{ $departamento }}</td>
      <td class="num">{{ number_format($grupo->sum('monto'), 2) }}</td>
      <td class="num">{{ number_format($grupo->sum('anticipos'), 2) }}</td>
      <td class="num">{{ number_format($grupo->sum('total'), 2) }}</td>
    </tr>
    @endforeach
  </tbody>
  <tr class="totals-row">
    <td colspan="{{ $conCuenta ? 2 : 1 }}" style="text-align:left; padding-left:4px;">TOTAL GENERAL</td>
    <td class="num">{{ number_format($filas->sum('monto'), 2) }}</td>
    <td class="num">{{ number_format($filas->sum('anticipos'), 2) }}</td>
    <td class="num">{{ number_format($filas->sum('total'), 2) }}</td>
  </tr>
</table>

<table class="footer">
  <tr>
    <td><div class="linea">Elaborado por</div></td>
    <td><div class="linea">Revisado por</div></td>
    <td><div class="linea">Autorizado por</div></td>
  </tr>
</table>

</body>
</html>
