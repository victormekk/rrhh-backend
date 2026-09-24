<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<style>
/* Márgenes nativos de página: se repiten en cada página física (a diferencia del padding en .page) */
@page { size: letter portrait; margin: 1.27cm; }
/* :not(html):not(body) evita un bug de dompdf: si <body> recibe margin:0
   explicito y la pagina tiene una <table>, dompdf ignora el margen del
   @page y dibuja los fondos de la tabla de borde a borde de la hoja. */
*:not(html):not(body) { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: DejaVu Sans, sans-serif; font-size: 10.5px; color: #1e293b; line-height: 1.45; }

.page { padding: 0; }

/* ── Marca Hotel Palma Real ── */
/* Marrón #3b2b16 y dorado #b9921a extraídos del logotipo oficial */

/* ── Header ── */
table.hdr { width: 100%; border-collapse: collapse; border-bottom: 2px solid #3b2b16; padding-bottom: 12px; margin-bottom: 14px; }
.hdr-logo { width: 148px; vertical-align: middle; }
.hdr-logo img { width: 148px; height: auto; display: block; }
.hdr-info { vertical-align: middle; padding-left: 16px; }
.hdr-info h2 { font-size: 24px; color: #3b2b16; font-weight: bold; letter-spacing: 0.4px; line-height: 1.2; }
.hdr-info p  { font-size: 9.5px; color: #b9921a; margin-top: 5px; font-weight: 600; letter-spacing: 0.3px; text-transform: uppercase; }
.hdr-doc { text-align: right; vertical-align: middle; white-space: nowrap; }
.hdr-doc .num   { font-size: 16px; font-weight: bold; color: #3b2b16; }
.hdr-doc .fecha { font-size: 9px; color: #8a7654; margin-top: 4px; }

/* ── Base legal ── */
.legal { background: #f8f2df; border-left: 3px solid #b9921a; padding: 6px 8px; margin-bottom: 12px; font-size: 6.9px; color: #3b2b16; line-height: 1.4; white-space: nowrap; }
.legal strong { color: #8a6d10; }

/* ── Título de sección ── */
.sec { font-size: 9px; font-weight: bold; text-transform: uppercase; letter-spacing: 0.9px; color: #3b2b16; border-bottom: 1px solid #3b2b16; padding-bottom: 4px; margin-bottom: 7px; }

/* ── Campos de datos ── */
table.fields { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
table.fields td { vertical-align: top; padding-right: 16px; }
table.fields td:last-child { padding-right: 0; }
.lbl { display: block; font-size: 8px; color: #64748b; text-transform: uppercase; letter-spacing: 0.2px; margin-top: 6px; }
.lbl:first-child { margin-top: 0; }
.val { display: block; font-size: 11px; font-weight: 600; color: #0f172a; border-bottom: 1px dotted #cbd5e1; padding-bottom: 2px; margin-bottom: 2px; }

/* ── Cuadros de días ── */
table.dias { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
table.dias td { text-align: center; padding: 8px 4px; border-right: 1px solid #000; }
table.dias td:last-child { border-right: none; }
.d-lbl { font-size: 8px; color: #64748b; text-transform: uppercase; }
.d-num { font-size: 23px; font-weight: bold; color: #3b2b16; line-height: 1.15; margin: 3px 0; }
.d-sub { font-size: 7.5px; color: #94a3b8; }
.hl .d-lbl { color: #8a6d10; }
.hl .d-num { color: #3b2b16; }
.hl .d-sub { color: #64748b; }

/* ── Período de ausencia ── */
.periodo { color: #3b2b16; text-align: center; padding: 8px 0; margin-bottom: 12px; }
.p-tit { font-size: 8.5px; color: #8a6d10; text-transform: uppercase; letter-spacing: .7px; }
.p-rng { font-size: 18px; font-weight: bold; color: #3b2b16; margin: 6px 0 4px; }
.p-sub { font-size: 9px; color: #64748b; }
.p-ret { font-size: 11px; font-weight: 600; margin-top: 5px; color: #3b2b16; }

/* ── Observaciones ── */
.obs { border: 1px solid #e2e8f0; border-radius: 3px; padding: 8px 14px; min-height: 30px; font-size: 9.5px; color: #334155; line-height: 1.6; margin-bottom: 14px; }

/* ── Firmas ── */
table.firmas { width: 100%; border-collapse: collapse; margin-top: 24px; }
table.firmas td { width: 50%; text-align: center; vertical-align: top; padding: 0 42px; }
.f-espacio { height: 36px; border-bottom: 1px solid #334155; }
.f-nombre  { font-size: 10.5px; font-weight: bold; color: #0f172a; margin-top: 6px; }
.f-cargo   { font-size: 9px; color: #64748b; margin-top: 2px; }
.f-dni     { font-size: 8.5px; color: #94a3b8; margin-top: 2px; }

/* ── Pie de página ── */
.footer { margin-top: 16px; border-top: 1px solid #e2e8f0; padding-top: 5px; font-size: 8px; color: #64748b; text-align: center; }
</style>
</head>
<body>

@php
  $emp     = $solicitud->empleado;
  $il      = $emp->informacionLaboral;
  $fInicio = $il?->fecha_inicio ? \Carbon\Carbon::parse($il->fecha_inicio) : null;
  $fSolIni = \Carbon\Carbon::parse($solicitud->fecha_inicio);
  $fSolFin = \Carbon\Carbon::parse($solicitud->fecha_fin);

  $diasSolicitudAnterior = $solicitudAnterior->dias_tomados ?? 0;
  $saldoRestante         = max(0, $saldo['saldo']);

  $meses = ['enero','febrero','marzo','abril','mayo','junio',
            'julio','agosto','septiembre','octubre','noviembre','diciembre'];
@endphp

<div class="page">

  {{-- ══ ENCABEZADO ══ --}}
  <table class="hdr">
    <tr>
      <td class="hdr-logo"><img src="{{ public_path('images/hpr_logo.png') }}" alt="Palma Real Hotel y Villas"></td>
      <td class="hdr-info">
        <h2>CONSTANCIA DE VACACIONES</h2>
        <p>Departamento de Recursos Humanos &nbsp;·&nbsp; La Ceiba, Atlántida, Honduras</p>
      </td>
      <td class="hdr-doc">
        <div class="num">N° {{ $correlativo }}</div>
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
        <span class="lbl">Cargo</span>
        <span class="val">{{ $emp->cargo?->nombre ?? '—' }}</span>
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
        <div class="d-lbl">Tomados este período</div>
        <div class="d-num">{{ number_format($saldo['dias_tomados_periodo'], 0) }}</div>
        <div class="d-sub">período actual</div>
      </td>
      <td>
        <div class="d-lbl">Solicitud anterior</div>
        <div class="d-num">{{ number_format($diasSolicitudAnterior, 0) }}</div>
        <div class="d-sub">{{ $solicitudAnterior ? \Carbon\Carbon::parse($solicitudAnterior->fecha_inicio)->format('d/m/Y') : 'sin registro previo' }}</div>
      </td>
      <td class="hl">
        <div class="d-lbl">Esta solicitud</div>
        <div class="d-num">{{ number_format($solicitud->dias_tomados, 0) }}</div>
        <div class="d-sub">días laborables</div>
      </td>
      <td>
        <div class="d-lbl">Días disponibles</div>
        <div class="d-num">{{ number_format($saldoRestante, 0) }}</div>
        <div class="d-sub">saldo restante</div>
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
        <div class="f-nombre">Hotel Palma Real</div>
      </td>
    </tr>
  </table>

  {{-- ══ PIE ══ --}}
  <div class="footer">
    Emitido por el Depto. de Recursos Humanos · Hotel Palma Real &nbsp;·&nbsp;
    Art. 346 Código de Trabajo de Honduras &nbsp;·&nbsp;
    N° {{ $correlativo }} &nbsp;·&nbsp;
    {{ now()->format('d/m/Y H:i') }}
  </div>

</div>{{-- /page --}}
</body>
</html>
