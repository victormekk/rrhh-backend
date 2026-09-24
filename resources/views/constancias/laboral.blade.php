<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<style>
  @page { size: letter portrait; margin: 2.5cm 3cm; }
  /* :not(html):not(body) evita un bug de dompdf: si <body> recibe margin:0
     explicito y la pagina tiene una <table>, dompdf ignora el margen del @page. */
  *:not(html):not(body) { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1e293b; line-height: 1.6; }

  /* Marrón #3b2b16 y dorado #b9921a extraídos del logotipo oficial */
  .header { text-align: center; border-bottom: 2px solid #3b2b16; padding-bottom: 10px; margin-bottom: 25px; }
  .header img { height: 140px; margin-bottom: 8px; }
  .header h3 { font-size: 18px; color: #3b2b16; font-weight: bold; letter-spacing: 0.4px; margin-top: 10px; }

  .cuerpo p { text-align: justify; font-size: 11.5px; margin-bottom: 18px; }
  .cuerpo strong { color: #0f172a; }

  .firma-wrap   { margin-top: 140px; text-align: center; }
  .firma-linea  { width: 260px; border-top: 1px solid #1e293b; margin: 0 auto 6px; }
  .firma-nombre { font-size: 11px; font-weight: bold; color: #0f172a; line-height: 1.3; }
  .firma-cargo  { font-size: 9.5px; color: #64748b; margin-top: 2px; line-height: 1.3; }

  .footer { position: fixed; bottom: 0; left: 0; right: 0; padding-top: 6px;
            font-size: 9px; line-height: 1; color: #64748b; text-align: center; }
  .footer p { margin-bottom: 0; }
  .footer .footer-empresa { font-weight: bold; color: #64748b; }
</style>
</head>
<body>
<div class="page">

  <div class="header">
    <img src="{{ public_path('images/hpr_logo.png') }}" alt="Hotel y Villas Palma Real">
    <h3>CONSTANCIA</h3>
  </div>

  <div class="cuerpo">
    <p>
      El Departamento de Recursos Humanos de <strong>Inversiones y Servicios S.A (Hotel y Villas Palma Real)</strong> hace constar que
      <strong>{{ $emp->nombres }} {{ $emp->apellidos }}</strong>,
      con documento nacional de identidad <strong>{{ $emp->cedula ?? '—' }}</strong>,
      labora desde el
      <strong>{{ $fechaInicio->format('d') }} de {{ ucfirst($meses[$fechaInicio->month - 1]) }} de {{ $fechaInicio->format('Y') }}</strong>,
      desempeñando el cargo de <strong>{{ $emp->cargo?->nombre ?? '—' }}</strong>,
      devenga un salario mensual de: <strong>{{ $simboloMoneda }} {{ number_format($salarioMensual, 2) }}</strong>
      (<strong>{{ $montoEnLetras }}</strong>)@if($ihssMensual > 0) y se le deducen del IHSS <strong>{{ $simboloMoneda }} {{ number_format($ihssMensual, 2) }}</strong>@endif.
    </p>
    <p>
      Y para los fines que estime convenientes se extiende la presente constancia en la ciudad de La Ceiba,
      a los {{ now()->format('d') }} días del mes de {{ ucfirst($meses[now()->month - 1]) }} de
      {{ now()->format('Y') }}.
    </p>
  </div>

  <div class="firma-wrap">
    <div class="firma-linea"></div>
    <div class="firma-nombre">Lic. Deisy Anabel Pavón</div>
    <div class="firma-cargo">Contador General - Gerente de Recursos Humanos</div>
    <div class="firma-cargo">Hotel y Villas Palma Real</div>
  </div>

  <div class="footer">
    <p class="footer-empresa">Inversiones y Servicios S.A - Hotel y Villas Palma Real</p>
    <p>RTN: 08019995366300</p>
    <p>Km. 20 Carretera La Ceiba - Trujillo, Roma, Atlántida. Tel: (504) 2407-0000</p>
    <p>Correo: admon@grupopalmareal.com &nbsp;·&nbsp; www.grupopalmareal.com</p>
    <p>N° {{ $correlativo }}</p>
  </div>

</div>
</body>
</html>
