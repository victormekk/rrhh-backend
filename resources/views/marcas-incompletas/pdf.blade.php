<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<style>
  /* :not(html):not(body): ver la nota en log-sistema/pdf.blade.php (bug de dompdf con margin:0 en body) */
  *:not(html):not(body) { box-sizing: border-box; margin: 0; padding: 0; }
  @page { size: letter portrait; margin: 1.27cm; }
  body { font-family: DejaVu Sans, sans-serif; font-size: 8px; color: #1e293b; }

  .meta { margin-bottom: 10px; font-size: 8px; padding: 6px 10px; background: #f8f2df; border-radius: 4px; border: 1px solid #e3c777; }
  .meta-item { display: inline-block; margin-right: 20px; }
  .meta-item span { color: #8a6d10; }
  .meta-item strong { color: #3b2b16; }

  table { width: 100%; border-collapse: collapse; table-layout: fixed; }
  thead tr { background-color: #3b2b16; color: white; }
  thead th { padding: 5px 6px; text-align: left; font-size: 7.5px; font-weight: bold; }
  tbody tr:nth-child(even) { background-color: #f8fafc; }
  tbody tr:nth-child(odd)  { background-color: #ffffff; }
  tbody td { padding: 4px 6px; font-size: 7.5px; border-bottom: 1px solid #e2e8f0; vertical-align: top; overflow-wrap: break-word; word-break: break-word; }
  td.dep { color: #3b2b16; font-weight: bold; }
  td.gris { color: #64748b; }

  .badge { display: inline-block; padding: 1.5px 6px; border-radius: 8px; font-size: 6.5px; font-weight: bold; text-transform: uppercase; }
  .entrada   { background: #d1fae5; color: #065f46; }
  .salida    { background: #dbeafe; color: #1e40af; }
  .pendiente { color: #b45309; font-weight: bold; }
  .ok        { color: #065f46; }

  .empty { text-align: center; padding: 30px; color: #94a3b8; font-size: 9px; }
  .firma { margin-top: 40px; }
  .firma div { display: inline-block; width: 45%; text-align: center; border-top: 1px solid #94a3b8; padding-top: 4px; margin-right: 8%; font-size: 7.5px; color: #475569; }
  .footer { margin-top: 12px; font-size: 7px; color: #64748b; text-align: right; }
</style>
</head>
<body>

  <x-pdf-encabezado titulo="MARCAS INCOMPLETAS" subtitulo="Días con una sola marca en el reloj — para verificar con cada jefe de departamento">
    N° {{ $correlativo }} &nbsp;|&nbsp; Generado el {{ now()->format('d/m/Y H:i') }}
  </x-pdf-encabezado>

  <div class="meta">
    <div class="meta-item"><span>Período: </span><strong>{{ \Carbon\Carbon::parse($desde)->format('d/m/Y') }} al {{ \Carbon\Carbon::parse($hasta)->format('d/m/Y') }}</strong></div>
    <div class="meta-item"><span>Casos: </span><strong>{{ $filas->count() }}</strong></div>
    <div class="meta-item"><span>Justificados: </span><strong>{{ $filas->whereNotNull('justificacion')->count() }}</strong></div>
    <div class="meta-item"><span>Pendientes: </span><strong>{{ $filas->whereNull('justificacion')->count() }}</strong></div>
  </div>

  @if($filas->isEmpty())
    <div class="empty">No hay marcas incompletas en el período.</div>
  @else
    <table>
      <thead>
        <tr>
          <th style="width:16%">Departamento</th>
          <th style="width:26%">Empleado</th>
          <th style="width:15%">Fecha</th>
          <th style="width:15%">Marca registrada</th>
          <th style="width:28%">Justificación</th>
        </tr>
      </thead>
      <tbody>
        @foreach($filas as $f)
        <tr>
          <td class="dep">{{ $f['departamento'] ?? '—' }}</td>
          <td>{{ $f['empleado'] }}<br><span class="gris">{{ $f['cargo'] ?? '' }}</span></td>
          <td>{{ \Carbon\Carbon::parse($f['fecha'])->format('d/m/Y') }}<br><span class="gris">{{ $f['dia'] }}</span></td>
          <td>
            {{ substr($f['hora'], 0, 5) }}
            @if($f['tipo'])
              <span class="badge {{ str_starts_with($f['tipo'], 'Entrada') ? 'entrada' : 'salida' }}">{{ $f['tipo'] }}</span>
            @endif
          </td>
          <td>
            @if($f['justificacion'])
              <span class="ok">{{ $f['justificacion']['motivo'] }}</span>
            @else
              <span class="pendiente">Pendiente</span>
            @endif
          </td>
        </tr>
        @endforeach
      </tbody>
    </table>
  @endif

  <div class="firma">
    <div>Revisado por (Recursos Humanos)</div><div>Visto bueno (Jefe de departamento)</div>
  </div>

  <div class="footer">
    Documento generado el {{ now()->format('d/m/Y \a \l\a\s H:i') }} · Sistema RRHH Hotel Palma Real
  </div>

</body>
</html>
