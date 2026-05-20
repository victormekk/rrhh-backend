<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<style>
/* Eliminar márgenes de página y delegarlos al wrapper */
@page { size: letter portrait; margin: 0; }
* { box-sizing: border-box; margin: 0; padding: 0; }
body { margin: 0; padding: 0; font-family: DejaVu Sans, sans-serif; font-size: 8px; color: #1e293b; line-height: 1.35; }

/* Wrapper principal — aquí van los márgenes reales */
.page {
  padding-top: 25mm;
  padding-bottom: 25mm;
  padding-left: 30mm;
  padding-right: 30mm;
}

/* ── Header ── */
table.hdr { width: 100%; border-collapse: collapse; border-bottom: 2px solid #1d4ed8; padding-bottom: 7px; margin-bottom: 7px; }
.hdr-logo { width: 42px; background: #1d4ed8; border-radius: 4px; text-align: center; vertical-align: middle; color: #fff; font-size: 15px; font-weight: bold; padding: 8px 0; }
.hdr-info { vertical-align: middle; padding-left: 10px; }
.hdr-info h1 { font-size: 12.5px; color: #1d4ed8; font-weight: bold; letter-spacing: 0.3px; }
.hdr-info h2 { font-size: 9px; color: #334155; margin-top: 2px; }
.hdr-info p  { font-size: 6.5px; color: #64748b; margin-top: 1px; }
.hdr-doc { text-align: right; vertical-align: middle; white-space: nowrap; }
.hdr-doc .num   { font-size: 11px; font-weight: bold; color: #1d4ed8; }
.hdr-doc .fecha { font-size: 6.5px; color: #64748b; margin-top: 2px; }

/* ── Base legal ── */
.legal { background: #f0f9ff; border-left: 2.5px solid #0ea5e9; padding: 5px 9px; margin-bottom: 8px; font-size: 7px; color: #0c4a6e; line-height: 1.55; }
.legal strong { color: #0369a1; }

/* ── Título de sección ── */
.sec { font-size: 6.5px; font-weight: bold; text-transform: uppercase; letter-spacing: 0.9px; color: #fff; background: #1d4ed8; padding: 3px 8px; margin-bottom: 5px; }

/* ── Campos de datos ── */
table.fields { width: 100%; border-collapse: collapse; margin-bottom: 8px; }
table.fields td { vertical-align: top; padding-right: 10px; }
table.fields td:last-child { padding-right: 0; }
.lbl { display: block; font-size: 6px; color: #64748b; text-transform: uppercase; letter-spacing: 0.2px; margin-top: 3px; }
.lbl:first-child { margin-top: 0; }
.val { display: block; font-size: 8px; font-weight: 600; color: #0f172a; border-bottom: 1px dotted #cbd5e1; padding-bottom: 1px; margin-bottom: 3px; }

/* ── Cuadros de días ── */
table.dias { width: 100%; border-collapse: collapse; border: 1px solid #bfdbfe; margin-bottom: 8px; }
table.dias td { text-align: center; padding: 5px 2px; border-right: 1px solid #bfdbfe; }
table.dias td:last-child { border-right: none; }
.d-lbl { font-size: 6px; color: #64748b; text-transform: uppercase; }
.d-num { font-size: 18px; font-weight: bold; color: #1d4ed8; line-height: 1.1; margin: 1px 0; }
.d-sub { font-size: 6px; color: #94a3b8; }
.hl { background: #1d4ed8; }
.hl .d-lbl { color: #bfdbfe; }
.hl .d-num { color: #fff; }
.hl .d-sub { color: #93c5fd; }

/* ── Período de ausencia ── */
.periodo { background: #1e40af; color: #fff; text-align: center; padding: 7px 14px; margin-bottom: 8px; border-radius: 3px; }
.p-tit { font-size: 6.5px; opacity: .75; text-transform: uppercase; letter-spacing: .7px; }
.p-rng { font-size: 11.5px; font-weight: bold; margin: 3px 0 2px; }
.p-sub { font-size: 7px; opacity: .85; }
.p-ret { font-size: 7px; margin-top: 3px; opacity: .9; }

/* ── Observaciones ── */
.obs { border: 1px solid #e2e8f0; border-radius: 2px; padding: 5px 9px; min-height: 22px; font-size: 7.5px; color: #334155; line-height: 1.55; margin-bottom: 10px; }

/* ── Firmas ── */
table.firmas { width: 100%; border-collapse: collapse; margin-top: 18px; }
table.firmas td { text-align: center; padding: 0 30px; }
.f-espacio { height: 32px; border-bottom: 1px solid #334155; }
.f-nombre  { font-size: 8px; font-weight: bold; color: #0f172a; margin-top: 4px; }
.f-cargo   { font-size: 7px; color: #64748b; margin-top: 1px; }
.f-dni     { font-size: 6.5px; color: #94a3b8; margin-top: 1px; }

/* ── Pie de página ── */
.footer { margin-top: 12px; border-top: 1px solid #e2e8f0; padding-top: 4px; font-size: 6.5px; color: #94a3b8; text-align: center; }
</style>
</head>
<body>

@php
  $emp     = $solicitud->empleado;
  $il      = $emp->informacionLaboral;
  $fInicio = $il?->fecha_inicio ? \Carbon\Carbon::parse($il->fecha_inicio) : null;
  $fSolIni = \Carbon\Carbon::parse($solicitud->fecha_inicio);
  $fSolFin = \Carbon\Carbon::parse($solicitud->fecha_fin);

  $retorno = $fSolFin->copy()->addDay();
  while ($retorno->dayOfWeek === \Carbon\Carbon::SUNDAY) $retorno->addDay();

  $diasPrevios   = max(0, $saldo['dias_tomados'] - $solicitud->dias_tomados);
  $saldoRestante = max(0, $saldo['saldo']);

  $meses = ['enero','febrero','marzo','abril','mayo','junio',
            'julio','agosto','septiembre','octubre','noviembre','diciembre'];
@endphp

<div class="page">

  {{-- ══ ENCABEZADO ══ --}}
  <table class="hdr">
    <tr>
      <td class="hdr-logo" width="42">HR</td>
      <td class="hdr-info">
        <h1>Hotel Palma Real</h1>
        <h2>CONSTANCIA DE VACACIONES</h2>
        <p>Departamento de Recursos Humanos &nbsp;·&nbsp; Tegucigalpa, Honduras</p>
      </td>
      <td class="hdr-doc">
        <div class="num">N° {{ str_pad($solicitud->id, 5, '0', STR_PAD_LEFT) }}</div>
        <div class="fecha">Emitido: {{ now()->format('d/m/Y') }}</div>
      </td>
    </tr>
  </table>

  {{-- ══ BASE LEGAL ══ --}}
  <div class="legal">
    <strong>Base legal:</strong> Art. 346 Código de Trabajo de Honduras &nbsp;·&nbsp;
    <strong>1 año = 10 días · 2 años = 12 días · 3 años = 15 días · 4 años o más = 20 días</strong>
    &nbsp;(días laborables; los domingos no se contabilizan).
  </div>

  {{-- ══ I. DATOS DEL EMPLEADO ══ --}}
  <div class="sec">I. Datos del Empleado</div>
  <table class="fields">
    <tr>
      <td width="36%">
        <span class="lbl">Nombre completo</span>
        <span class="val">{{ $emp->nombres }} {{ $emp->apellidos }}</span>
        <span class="lbl">DNI / Cédula de identidad</span>
        <span class="val">{{ $emp->cedula ?? '—' }}</span>
        <span class="lbl">RTN</span>
        <span class="val">{{ $emp->rtn ?? '—' }}</span>
      </td>
      <td width="32%">
        <span class="lbl">Puesto</span>
        <span class="val">{{ $emp->puesto?->nombre ?? '—' }}</span>
        <span class="lbl">Departamento</span>
        <span class="val">{{ $emp->departamento?->nombre ?? '—' }}</span>
        <span class="lbl">Tipo de contrato</span>
        <span class="val">{{ $il?->tipo_contrato ?? '—' }}</span>
      </td>
      <td width="32%">
        <span class="lbl">Fecha de ingreso</span>
        <span class="val">{{ $fInicio ? $fInicio->format('d/m/Y') : '—' }}</span>
        <span class="lbl">Antigüedad</span>
        <span class="val">
          {{ $saldo['anios_laborados'] }} {{ $saldo['anios_laborados'] === 1 ? 'año' : 'años' }}
          @if($fInicio) ({{ $fInicio->diffInMonths(now()) % 12 }} meses) @endif
        </span>
        <span class="lbl">Período vacacional activo</span>
        <span class="val">
          @if($saldo['periodo_inicio'])
            {{ \Carbon\Carbon::parse($saldo['periodo_inicio'])->format('d/m/Y') }} — {{ \Carbon\Carbon::parse($saldo['periodo_fin'])->format('d/m/Y') }}
          @else — @endif
        </span>
      </td>
    </tr>
  </table>

  {{-- ══ II. DETALLE DE VACACIONES ══ --}}
  <div class="sec">II. Detalle de Vacaciones</div>
  <table class="dias">
    <tr>
      <td>
        <div class="d-lbl">Días por ley</div>
        <div class="d-num">{{ number_format($saldo['dias_por_ley'], 0) }}</div>
        <div class="d-sub">según antigüedad</div>
      </td>
      <td>
        <div class="d-lbl">Tomados previos</div>
        <div class="d-num">{{ number_format($diasPrevios, 0) }}</div>
        <div class="d-sub">este período</div>
      </td>
      <td class="hl">
        <div class="d-lbl">Esta solicitud</div>
        <div class="d-num">{{ number_format($solicitud->dias_tomados, 0) }}</div>
        <div class="d-sub">días laborables</div>
      </td>
      <td>
        <div class="d-lbl">Saldo restante</div>
        <div class="d-num">{{ number_format($saldoRestante, 0) }}</div>
        <div class="d-sub">días disponibles</div>
      </td>
    </tr>
  </table>

  {{-- ══ III. PERÍODO DE AUSENCIA ══ --}}
  <div class="sec">III. Período de Ausencia</div>
  <div class="periodo">
    <div class="p-tit">El empleado se ausentará durante el siguiente período</div>
    <div class="p-rng">
      Del {{ $fSolIni->format('d') }} de {{ $meses[$fSolIni->month - 1] }}
      al {{ $fSolFin->format('d') }} de {{ $meses[$fSolFin->month - 1] }}
      de {{ $fSolFin->format('Y') }}
    </div>
    <div class="p-sub">{{ number_format($solicitud->dias_tomados, 0) }} días laborables &nbsp;·&nbsp; domingos excluidos conforme al Código de Trabajo</div>
    <div class="p-ret">Fecha de reintegro: <strong>{{ $retorno->format('d') }} de {{ $meses[$retorno->month - 1] }} de {{ $retorno->format('Y') }}</strong></div>
  </div>

  {{-- ══ IV. OBSERVACIONES ══ --}}
  <div class="sec">IV. Observaciones</div>
  <div class="obs">{{ $solicitud->observaciones ?: 'Sin observaciones adicionales.' }}</div>

  {{-- ══ FIRMAS ══ --}}
  <table class="firmas">
    <tr>
      <td>
        <div class="f-espacio"></div>
        <div class="f-nombre">{{ $emp->nombres }} {{ $emp->apellidos }}</div>
        <div class="f-cargo">Empleado(a)</div>
        <div class="f-dni">DNI: {{ $emp->cedula ?? '—' }}</div>
      </td>
      <td>
        <div class="f-espacio"></div>
        <div class="f-nombre">&nbsp;</div>
        <div class="f-cargo">Gerente de Recursos Humanos</div>
        <div class="f-dni">Hotel Palma Real</div>
      </td>
    </tr>
  </table>

  {{-- ══ PIE ══ --}}
  <div class="footer">
    Emitido por el Depto. de Recursos Humanos · Hotel Palma Real &nbsp;·&nbsp;
    Art. 346 Código de Trabajo de Honduras &nbsp;·&nbsp;
    N° {{ str_pad($solicitud->id, 5, '0', STR_PAD_LEFT) }} &nbsp;·&nbsp;
    {{ now()->format('d/m/Y H:i') }}
  </div>

</div>{{-- /page --}}
</body>
</html>
