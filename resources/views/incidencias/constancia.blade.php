<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<style>
/* Márgenes nativos de página: se repiten en cada página física (a diferencia del padding en .page) */
@page { size: letter portrait; margin: 25mm 30mm; }
* { box-sizing: border-box; margin: 0; padding: 0; }
body { margin: 0; padding: 0; font-family: DejaVu Sans, sans-serif; font-size: 8px; color: #1e293b; line-height: 1.35; }

.page { padding: 0; }

/* ── Marca Hotel Palma Real ── */
/* Marrón #3b2b16 y dorado #b9921a extraídos del logotipo oficial */

/* ── Header ── */
table.hdr { width: 100%; border-collapse: collapse; border-bottom: 2px solid #3b2b16; padding-bottom: 9px; margin-bottom: 9px; }
.hdr-logo { width: 100px; vertical-align: middle; }
.hdr-logo img { width: 100px; height: auto; display: block; }
.hdr-info { vertical-align: middle; padding-left: 14px; }
.hdr-info h2 { font-size: 19px; color: #3b2b16; font-weight: bold; letter-spacing: 0.4px; line-height: 1.2; }
.hdr-info p  { font-size: 7.5px; color: #b9921a; margin-top: 4px; font-weight: 600; letter-spacing: 0.3px; text-transform: uppercase; }
.hdr-doc { text-align: right; vertical-align: middle; white-space: nowrap; }
.hdr-doc .num   { font-size: 13px; font-weight: bold; color: #3b2b16; }
.hdr-doc .fecha { font-size: 7px; color: #8a7654; margin-top: 3px; }

/* ── Base legal ── */
.legal { background: #f8f2df; border-left: 2.5px solid #b9921a; padding: 5px 9px; margin-bottom: 8px; font-size: 7px; color: #3b2b16; line-height: 1.55; }
.legal strong { color: #8a6d10; }

/* ── Título de sección ── */
.sec { font-size: 6.5px; font-weight: bold; text-transform: uppercase; letter-spacing: 0.9px; color: #fff; background: #3b2b16; padding: 3px 8px; margin-bottom: 5px; }

/* ── Campos de datos ── */
table.fields { width: 100%; border-collapse: collapse; margin-bottom: 8px; }
table.fields td { vertical-align: top; padding-right: 10px; }
table.fields td:last-child { padding-right: 0; }
.lbl { display: block; font-size: 6px; color: #64748b; text-transform: uppercase; letter-spacing: 0.2px; margin-top: 3px; }
.lbl:first-child { margin-top: 0; }
.val { display: block; font-size: 8px; font-weight: 600; color: #0f172a; border-bottom: 1px dotted #cbd5e1; padding-bottom: 1px; margin-bottom: 3px; }

/* ── Cuadro de gravedad ── */
table.grado { width: 100%; border-collapse: collapse; border: 1px solid #e3c777; margin-bottom: 8px; }
table.grado td { text-align: center; padding: 5px 2px; border-right: 1px solid #e3c777; }
table.grado td:last-child { border-right: none; }
.g-lbl { font-size: 6px; color: #64748b; text-transform: uppercase; }
.g-num { font-size: 13px; font-weight: bold; color: #3b2b16; line-height: 1.3; margin: 1px 0; }
.g-sub { font-size: 6px; color: #94a3b8; }
.hl { background: {{ ['Leve' => '#16a34a', 'Moderada' => '#d97706', 'Grave' => '#dc2626'][$incidencia->grado] ?? '#3b2b16' }}; }
.hl .g-lbl { color: rgba(255,255,255,.75); }
.hl .g-num { color: #fff; }
.hl .g-sub { color: rgba(255,255,255,.85); }

/* ── Descripción de los hechos ── */
.obs { border: 1px solid #e2e8f0; border-radius: 2px; padding: 7px 9px; min-height: 60px; font-size: 7.5px; color: #334155; line-height: 1.6; margin-bottom: 10px; }

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
  $emp   = $incidencia->empleado;
  $il    = $emp->informacionLaboral;
  $fInc  = \Carbon\Carbon::parse($incidencia->fecha_incidencia);
  $meses = ['enero','febrero','marzo','abril','mayo','junio',
            'julio','agosto','septiembre','octubre','noviembre','diciembre'];
@endphp

<div class="page">

  {{-- ══ ENCABEZADO ══ --}}
  <table class="hdr">
    <tr>
      <td class="hdr-logo"><img src="{{ public_path('images/hpr_logo.png') }}" alt="Palma Real Hotel y Villas"></td>
      <td class="hdr-info">
        <h2>CONSTANCIA DE INCIDENCIA</h2>
        <p>Departamento de Recursos Humanos &nbsp;·&nbsp; Tegucigalpa, Honduras</p>
      </td>
      <td class="hdr-doc">
        <div class="num">N° {{ str_pad($incidencia->id, 5, '0', STR_PAD_LEFT) }}</div>
        <div class="fecha">Emitido: {{ now()->format('d/m/Y') }}</div>
      </td>
    </tr>
  </table>

  {{-- ══ BASE LEGAL ══ --}}
  <div class="legal">
    <strong>Base legal:</strong> Reglamento Interno de Trabajo &nbsp;·&nbsp;
    Código de Trabajo de Honduras &nbsp;·&nbsp;
    Se deja constancia de la presente incidencia para el expediente laboral del colaborador.
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
      </td>
      <td width="32%">
        <span class="lbl">Puesto</span>
        <span class="val">{{ $emp->puesto?->nombre ?? '—' }}</span>
        <span class="lbl">Departamento</span>
        <span class="val">{{ $emp->departamento?->nombre ?? '—' }}</span>
      </td>
      <td width="32%">
        <span class="lbl">Tipo de contrato</span>
        <span class="val">{{ $il?->tipo_contrato ?? '—' }}</span>
        <span class="lbl">Fecha de la incidencia</span>
        <span class="val">{{ $fInc->format('d/m/Y') }}</span>
      </td>
    </tr>
  </table>

  {{-- ══ II. DETALLE DE LA INCIDENCIA ══ --}}
  <div class="sec">II. Detalle de la Incidencia</div>
  <table class="grado">
    <tr>
      <td width="34%">
        <div class="g-lbl">Título</div>
        <div class="g-num" style="font-size:9px;">{{ $incidencia->titulo }}</div>
        <div class="g-sub">registrado en el sistema</div>
      </td>
      <td width="33%">
        <div class="g-lbl">Fecha</div>
        <div class="g-num" style="font-size:9px;">
          {{ $fInc->format('d') }} de {{ $meses[$fInc->month - 1] }} de {{ $fInc->format('Y') }}
        </div>
        <div class="g-sub">día de la incidencia</div>
      </td>
      <td class="hl" width="33%">
        <div class="g-lbl">Gravedad</div>
        <div class="g-num">{{ strtoupper($incidencia->grado) }}</div>
        <div class="g-sub">clasificación</div>
      </td>
    </tr>
  </table>

  {{-- ══ III. DESCRIPCIÓN DE LOS HECHOS ══ --}}
  <div class="sec">III. Descripción de los Hechos</div>
  <div class="obs">{{ $incidencia->descripcion }}</div>

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
    N° {{ str_pad($incidencia->id, 5, '0', STR_PAD_LEFT) }} &nbsp;·&nbsp;
    {{ now()->format('d/m/Y H:i') }}
  </div>

</div>{{-- /page --}}
</body>
</html>
