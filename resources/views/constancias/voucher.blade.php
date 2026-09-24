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
body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1e293b; line-height: 1.45; }

.page { padding: 0; }

/* ── Marca Hotel Palma Real ── */
/* Marrón #3b2b16 y dorado #b9921a extraídos del logotipo oficial */

/* ── Header ── */
table.hdr { width: 100%; border-collapse: collapse; border-bottom: 2px solid #3b2b16; padding-bottom: 12px; margin-bottom: 14px; }
.hdr-logo { width: 200px; vertical-align: middle; }
.hdr-logo img { width: 200px; height: auto; display: block; }
.hdr-info { vertical-align: middle; padding-left: 16px; }
.hdr-info h2 { font-size: 23px; color: #3b2b16; font-weight: bold; letter-spacing: 0.4px; line-height: 1.2; margin-top: 8px; }
.hdr-info p  { font-size: 10px; color: #b9921a; margin-top: 5px; font-weight: 600; letter-spacing: 0.3px; text-transform: uppercase; }
.hdr-doc { text-align: right; vertical-align: middle; white-space: nowrap; }
.hdr-doc .num   { font-size: 16px; font-weight: bold; color: #3b2b16; }
.hdr-doc .fecha { font-size: 9px; color: #8a7654; margin-top: 4px; }

/* ── Título de sección ── */
.sec { font-size: 9px; font-weight: bold; text-transform: uppercase; letter-spacing: 0.9px; color: #3b2b16; background: #fff; border: 1px solid #3b2b16; padding: 4px 12px; margin-bottom: 7px; }

/* ── Campos de datos ── */
table.fields { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
table.fields td { vertical-align: top; padding-right: 14px; }
table.fields td:last-child { padding-right: 0; }
.lbl { display: block; font-size: 8.5px; color: #64748b; text-transform: uppercase; letter-spacing: 0.2px; margin-top: 6px; }
.lbl:first-child { margin-top: 0; }
.val { display: block; font-size: 11.5px; font-weight: 600; color: #0f172a; border-bottom: 1px dotted #cbd5e1; padding-bottom: 2px; margin-bottom: 2px; }

/* ── Detalle de pago (grilla 4 columnas) ── */
table.pago { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
table.pago td { width: 25%; padding: 8px; border: 1px solid #e2e8f0; }
.p-lbl { display: block; font-size: 8.5px; color: #64748b; text-transform: uppercase; letter-spacing: 0.2px; }
.p-val { display: block; font-size: 12px; font-weight: 600; color: #0f172a; margin-top: 3px; }
.p-ded .p-val { color: #dc2626; }

/* ── Resumen final ── */
table.resumen { width: 100%; border-collapse: collapse; border: 1px solid #e3c777; margin-bottom: 14px; }
table.resumen td { text-align: center; padding: 9px 4px; border-right: 1px solid #e3c777; }
table.resumen td:last-child { border-right: none; }
.r-lbl { font-size: 8.5px; color: #64748b; text-transform: uppercase; }
.r-num { font-size: 18px; font-weight: bold; color: #3b2b16; line-height: 1.2; margin: 3px 0; }
.r-num.neto { color: #166534; }
.r-num.ded  { color: #dc2626; }

/* ── Firma ── */
.firma-wrap   { margin-top: 3.2cm; text-align: center; }
.firma-linea  { width: 220px; border-top: 1px solid #1e293b; margin: 0 auto 6px; }
.f-cargo      { font-size: 9.5px; color: #64748b; margin-top: 2px; }

/* ── Pie de página ── */
.footer { position: fixed; bottom: 0; left: 0; right: 0; padding-top: 4px; font-size: 9px; line-height: 1; color: #64748b; text-align: center; }
.footer p { margin-bottom: 0; }
.footer .footer-empresa { font-weight: bold; color: #64748b; }
</style>
</head>
<body>

@php
  $emp = $detalle->empleado;
@endphp

<div class="page">

  {{-- ══ ENCABEZADO ══ --}}
  <table class="hdr">
    <tr>
      <td class="hdr-logo"><img src="{{ public_path('images/hpr_logo.png') }}" alt="Hotel y Villas Palma Real"></td>
      <td class="hdr-info">
        <h2>VOUCHER DE PAGO</h2>
      </td>
      <td class="hdr-doc">
        <div class="num">N° {{ $correlativo }}</div>
        <div class="fecha">Generado: {{ now()->format('d/m/Y') }}</div>
      </td>
    </tr>
  </table>

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
        <span class="lbl">Cargo</span>
        <span class="val">{{ $emp->cargo?->nombre ?? '—' }}</span>
        <span class="lbl">Departamento</span>
        <span class="val">{{ $detalle->departamento ?? '—' }}</span>
      </td>
      <td width="32%">
        <span class="lbl">Planilla</span>
        <span class="val">{{ $detalle->nombre_planilla }}</span>
        <span class="lbl">Fecha de la planilla</span>
        <span class="val">{{ \Carbon\Carbon::parse($detalle->fecha_generada)->format('d/m/Y') }}</span>
      </td>
    </tr>
  </table>

  {{-- ══ II. DETALLE DE PAGO ══ --}}
  <div class="sec">II. Detalle de Pago</div>
  <table class="pago">
    <tr>
      <td><span class="p-lbl">Días Trabajados</span><span class="p-val">{{ $detalle->dias_trabajados }}</span></td>
      <td><span class="p-lbl">Salario por Día</span><span class="p-val">L {{ number_format($detalle->salario_diario, 2) }}</span></td>
      <td><span class="p-lbl">Salario Base</span><span class="p-val">L {{ number_format($detalle->salario_base, 2) }}</span></td>
      <td><span class="p-lbl">Horas Extra ({{ $detalle->horas_extras }} h)</span><span class="p-val">L {{ number_format($detalle->monto_horas_extras, 2) }}</span></td>
    </tr>
    <tr>
      <td colspan="2">
        <span class="p-lbl">Otros Ingresos{{ $detalle->desc_ingresos ? ' — ' . $detalle->desc_ingresos : '' }}</span>
        <span class="p-val">L {{ number_format($detalle->otros_ingresos, 2) }}</span>
      </td>
      <td class="p-ded"><span class="p-lbl">IHSS</span><span class="p-val">L {{ number_format($detalle->ihss, 2) }}</span></td>
      <td class="p-ded"><span class="p-lbl">RAP</span><span class="p-val">L {{ number_format($detalle->retencion_ahorro, 2) }}</span></td>
    </tr>
    <tr>
      <td class="p-ded"><span class="p-lbl">ISR</span><span class="p-val">L {{ number_format($detalle->isr, 2) }}</span></td>
      <td class="p-ded"><span class="p-lbl">Crefisa</span><span class="p-val">L {{ number_format($detalle->crefisa, 2) }}</span></td>
      <td class="p-ded"><span class="p-lbl">Transporte</span><span class="p-val">L {{ number_format($detalle->transporte, 2) }}</span></td>
      <td class="p-ded"><span class="p-lbl">Radios</span><span class="p-val">L {{ number_format($detalle->radios, 2) }}</span></td>
    </tr>
    <tr>
      <td class="p-ded"><span class="p-lbl">Uniforme</span><span class="p-val">L {{ number_format($detalle->uniforme, 2) }}</span></td>
      <td class="p-ded"><span class="p-lbl">Garden</span><span class="p-val">L {{ number_format($detalle->garden, 2) }}</span></td>
      <td class="p-ded"><span class="p-lbl">I. Vecinal</span><span class="p-val">L {{ number_format($detalle->i_vecinal, 2) }}</span></td>
      <td class="p-ded">
        <span class="p-lbl">Otras Deducciones{{ $detalle->desc_otras_deducciones ? ' — ' . $detalle->desc_otras_deducciones : '' }}</span>
        <span class="p-val">L {{ number_format($detalle->otras_deducciones, 2) }}</span>
      </td>
    </tr>
  </table>

  {{-- ══ III. RESUMEN ══ --}}
  <table class="resumen">
    <tr>
      <td width="50%">
        <div class="r-lbl">Deducción Neta</div>
        <div class="r-num ded">L {{ number_format($detalle->deduccion_neta, 2) }}</div>
      </td>
      <td width="50%">
        <div class="r-lbl">Salario Neto</div>
        <div class="r-num neto">L {{ number_format($detalle->salario_neto, 2) }}</div>
      </td>
    </tr>
  </table>

  {{-- ══ FIRMA ══ --}}
  <div class="firma-wrap">
    <div class="firma-linea"></div>
    <div class="f-cargo">Generado por Recursos Humanos</div>
  </div>

  {{-- ══ PIE ══ --}}
  <div class="footer">
    <p>Documento informativo, no sustituye el comprobante oficial de pago.</p>
    <p class="footer-empresa">Inversiones y Servicios S.A - Hotel y Villas Palma Real</p>
    <p>RTN: 08019995366300</p>
    <p>Km. 20 Carretera La Ceiba - Trujillo, Roma, Atlántida. Tel: (504) 2407-0000</p>
    <p>Correo: admon@grupopalmareal.com &nbsp;·&nbsp; www.grupopalmareal.com</p>
  </div>

</div>{{-- /page --}}
</body>
</html>
