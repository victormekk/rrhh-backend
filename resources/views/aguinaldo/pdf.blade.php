<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<style>
  * { margin: 0; padding: 0; box-sizing: border-box; }
  body { font-family: DejaVu Sans, sans-serif; font-size: 9px; color: #1e293b; }
  .header { background: #1d4ed8; color: white; padding: 10px 14px; margin-bottom: 12px; }
  .header h1 { font-size: 14px; font-weight: bold; }
  .header p  { font-size: 9px; opacity: .85; margin-top: 2px; }
  .section-title { font-size: 11px; font-weight: bold; color: #1d4ed8;
                   border-bottom: 1px solid #93c5fd; padding-bottom: 3px; margin: 10px 0 6px; }
  table { width: 100%; border-collapse: collapse; font-size: 8px; }
  th { background: #dbeafe; color: #1e40af; font-weight: bold; text-align: center;
       padding: 4px 3px; border: 1px solid #93c5fd; }
  td { padding: 3px; border: 1px solid #e2e8f0; text-align: center; }
  td.left { text-align: left; }
  tr:nth-child(even) td { background: #f8fafc; }
  .totals td { background: #1d4ed8 !important; color: white; font-weight: bold; }
  .footer { margin-top: 20px; }
  .sigs { display: flex; justify-content: space-around; margin-top: 30px; }
  .sig { text-align: center; width: 28%; }
  .sig-line { border-top: 1px solid #64748b; margin-bottom: 4px; }
  .num { text-align: right; }
</style>
</head>
<body>

<div class="header">
  <h1>{{ $nombre }}</h1>
  <p>Hotel Palma Real &mdash; Generado el {{ now()->format('d/m/Y') }}
     &mdash; Tipo: {{ $meta->tipo_aguinaldo }}
     &mdash; Estado: {{ $meta->estado }}</p>
</div>

@if($fijos->isNotEmpty())
<div class="section-title">Empleados Fijos</div>
<table>
  <thead>
    <tr>
      <th>Departamento</th>
      <th>Nombre</th>
      <th>Apellido</th>
      <th>Cuenta</th>
      <th>Fecha Inicio</th>
      <th>Salario Base</th>
      <th>Días Trab.</th>
      <th>Anticipo</th>
      <th>Total Aguinaldo</th>
    </tr>
  </thead>
  <tbody>
    @foreach($fijos as $f)
    <tr>
      <td class="left">{{ $f->departamento }}</td>
      <td class="left">{{ $f->nombres }}</td>
      <td class="left">{{ $f->apellidos }}</td>
      <td>{{ $f->cuenta ?? '—' }}</td>
      <td>{{ $f->fecha_inicio ? $f->fecha_inicio->format('d/m/Y') : '—' }}</td>
      <td class="num">L {{ number_format($f->salario_base, 2) }}</td>
      <td>{{ $f->dias_trabajados }}</td>
      <td class="num">L {{ number_format($f->anticipo, 2) }}</td>
      <td class="num"><strong>L {{ number_format($f->total_aguinaldo, 2) }}</strong></td>
    </tr>
    @endforeach
    <tr class="totals">
      <td colspan="5" class="left"><strong>TOTALES</strong></td>
      <td class="num">L {{ number_format($totalesFijos['salario_base'], 2) }}</td>
      <td>{{ $totalesFijos['dias_trabajados'] }}</td>
      <td class="num">L {{ number_format($totalesFijos['anticipo'], 2) }}</td>
      <td class="num">L {{ number_format($totalesFijos['total_aguinaldo'], 2) }}</td>
    </tr>
  </tbody>
</table>
@endif

@if($extras->isNotEmpty())
<div class="section-title">Empleados Extras</div>
<table>
  <thead>
    <tr>
      <th>Departamento</th>
      <th>Nombre</th>
      <th>Apellido</th>
      <th>Cuenta</th>
      <th>Fecha Inicio</th>
      <th>Diario</th>
      <th>Días Prom.</th>
      <th>Antigüedad</th>
      <th>Subtotal</th>
      <th>Anticipos</th>
      <th>Total Aguinaldo</th>
    </tr>
  </thead>
  <tbody>
    @foreach($extras as $e)
    <tr>
      <td class="left">{{ $e->departamento }}</td>
      <td class="left">{{ $e->nombres }}</td>
      <td class="left">{{ $e->apellidos }}</td>
      <td>{{ $e->cuenta ?? '—' }}</td>
      <td>{{ $e->fecha_inicio ? $e->fecha_inicio->format('d/m/Y') : '—' }}</td>
      <td class="num">L {{ number_format($e->diario, 2) }}</td>
      <td>{{ $e->dias_promedio }}</td>
      <td class="num">L {{ number_format($e->antiguedad, 2) }}</td>
      <td class="num">L {{ number_format($e->subtotal, 2) }}</td>
      <td class="num">L {{ number_format($e->anticipos, 2) }}</td>
      <td class="num"><strong>L {{ number_format($e->total_aguinaldo, 2) }}</strong></td>
    </tr>
    @endforeach
    <tr class="totals">
      <td colspan="7" class="left"><strong>TOTALES</strong></td>
      <td class="num">L {{ number_format($totalesExtras['antiguedad'], 2) }}</td>
      <td class="num">L {{ number_format($totalesExtras['subtotal'], 2) }}</td>
      <td class="num">L {{ number_format($totalesExtras['anticipos'], 2) }}</td>
      <td class="num">L {{ number_format($totalesExtras['total_aguinaldo'], 2) }}</td>
    </tr>
  </tbody>
</table>
@endif

<div class="footer">
  <div class="sigs">
    <div class="sig"><div class="sig-line"></div><small>Gerente General</small></div>
    <div class="sig"><div class="sig-line"></div><small>Recursos Humanos</small></div>
    <div class="sig"><div class="sig-line"></div><small>Contabilidad</small></div>
  </div>
</div>
</body>
</html>
