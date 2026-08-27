<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }
  @page { size: letter landscape; margin: 25mm 30mm; }
  body { font-family: DejaVu Sans, sans-serif; font-size: 8px; color: #1e293b; }
  .page { padding: 0; }

  /* Marrón #3b2b16 y dorado #b9921a extraídos del logotipo oficial */
  .header { text-align: center; margin-bottom: 10px; padding-bottom: 8px; border-bottom: 2px solid #3b2b16; }
  .header img { height: 58px; margin-bottom: 6px; }
  .header h2 { font-size: 17px; margin-top: 2px; color: #3b2b16; font-weight: bold; letter-spacing: 0.4px; }
  .header p  { font-size: 8px; color: #8a6d10; margin-top: 3px; font-weight: 600; }

  .meta { display: flex; flex-wrap: wrap; gap: 20px; margin-bottom: 10px; font-size: 8px; padding: 6px 10px; background: #f8f2df; border-radius: 4px; border: 1px solid #e3c777; }
  .meta-item span { color: #8a6d10; }
  .meta-item strong { color: #3b2b16; }

  table { width: 100%; border-collapse: collapse; }
  thead tr { background-color: #3b2b16; color: white; }
  thead th { padding: 5px 6px; text-align: left; font-size: 7.5px; font-weight: bold; white-space: nowrap; }

  tbody tr:nth-child(even) { background-color: #f8fafc; }
  tbody tr:nth-child(odd)  { background-color: #ffffff; }
  tbody td { padding: 4px 6px; font-size: 7.5px; border-bottom: 1px solid #e2e8f0; vertical-align: top; }
  tbody td.fecha  { color: #64748b; white-space: nowrap; }
  tbody td.modulo { color: #3b2b16; font-weight: 600; }
  tbody td.usuario{ color: #64748b; }

  .badge { display: inline-block; padding: 1.5px 6px; border-radius: 8px; font-size: 6.5px; font-weight: bold; text-transform: uppercase; letter-spacing: 0.2px; }
  .badge-creado    { background: #d1fae5; color: #065f46; }
  .badge-editado   { background: #f8f2df; color: #8a6d10; }
  .badge-eliminado { background: #fee2e2; color: #991b1b; }
  .badge-login     { background: #ede9fe; color: #5b21b6; }
  .badge-logout    { background: #f1f5f9; color: #475569; }

  .empty { text-align: center; padding: 30px; color: #94a3b8; font-size: 9px; }

  .footer { margin-top: 12px; font-size: 7px; color: #94a3b8; text-align: right; }
</style>
</head>
<body>
<div class="page">

  <div class="header">
    <img src="{{ public_path('images/hpr_logo.png') }}" alt="Palma Real Hotel y Villas">
    <h2>LOG DEL SISTEMA</h2>
    <p>Historial de actividad — acciones registradas por los usuarios</p>
  </div>

  <div class="meta">
    <div class="meta-item">
      <span>Período: </span>
      <strong>
        @if($filtros['fecha_desde'] || $filtros['fecha_hasta'])
          {{ $filtros['fecha_desde'] ? \Carbon\Carbon::parse($filtros['fecha_desde'])->format('d/m/Y') : '…' }}
          —
          {{ $filtros['fecha_hasta'] ? \Carbon\Carbon::parse($filtros['fecha_hasta'])->format('d/m/Y') : '…' }}
        @else
          Todos los períodos
        @endif
      </strong>
    </div>
    @if($filtros['modulo'])
      <div class="meta-item"><span>Módulo: </span><strong>{{ $filtros['modulo'] }}</strong></div>
    @endif
    @if($filtros['accion'])
      <div class="meta-item"><span>Acción: </span><strong>{{ ucfirst($filtros['accion']) }}</strong></div>
    @endif
    @if($filtros['search'])
      <div class="meta-item"><span>Búsqueda: </span><strong>"{{ $filtros['search'] }}"</strong></div>
    @endif
    <div class="meta-item"><span>Total de registros: </span><strong>{{ $logs->count() }}</strong></div>
    <div class="meta-item"><span>Generado: </span><strong>{{ now()->format('d/m/Y H:i') }}</strong></div>
  </div>

  @if($logs->isEmpty())
    <div class="empty">No hay registros que coincidan con los filtros aplicados.</div>
  @else
    <table>
      <thead>
        <tr>
          <th style="width:12%">Fecha / Hora</th>
          <th style="width:12%">Módulo</th>
          <th style="width:10%">Acción</th>
          <th style="width:46%">Descripción</th>
          <th style="width:20%">Usuario</th>
        </tr>
      </thead>
      <tbody>
        @foreach($logs as $log)
        <tr>
          <td class="fecha">{{ \Carbon\Carbon::parse($log->created_at)->format('d/m/Y H:i') }}</td>
          <td class="modulo">{{ $log->objeto_actualizado ?? '—' }}</td>
          <td><span class="badge badge-{{ $log->accion }}">{{ $log->accion }}</span></td>
          <td>{{ $log->descripcion ?? '—' }}</td>
          <td class="usuario">{{ $log->usuario?->name ?? '—' }}</td>
        </tr>
        @endforeach
      </tbody>
    </table>
  @endif

  <div class="footer">
    Documento generado el {{ now()->format('d/m/Y \a \l\a\s H:i') }} · Sistema RRHH Hotel Palma Real
  </div>

</div>
</body>
</html>
