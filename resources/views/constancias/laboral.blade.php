<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<style>
  @page { size: letter portrait; margin: 1.27cm; }
  /* :not(html):not(body) evita un bug de dompdf: si <body> recibe margin:0
     explicito y la pagina tiene una <table>, dompdf ignora el margen del @page. */
  *:not(html):not(body) { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #1e293b; line-height: 1.6; }

  /* Marrón #3b2b16 y dorado #b9921a extraídos del logotipo oficial */
  .header { text-align: center; border-bottom: 2px solid #3b2b16; padding-bottom: 10px; margin-bottom: 30px; }
  .header img { height: 90px; margin-bottom: 8px; }
  .header h2 { font-size: 14px; color: #3b2b16; font-weight: bold; letter-spacing: 0.4px; }
  .header p  { font-size: 8px; color: #8a6d10; margin-top: 4px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.4px; }

  .titulo { text-align: center; font-size: 14px; font-weight: bold; color: #3b2b16;
            text-decoration: underline; letter-spacing: 0.5px; margin-bottom: 34px; }

  .cuerpo p { text-align: justify; font-size: 10.5px; margin-bottom: 18px; }
  .cuerpo strong { color: #0f172a; }

  .firma-wrap   { margin-top: 90px; text-align: center; }
  .firma-linea  { width: 260px; border-top: 1px solid #1e293b; margin: 0 auto 6px; }
  .firma-nombre { font-size: 10px; font-weight: bold; color: #0f172a; }
  .firma-cargo  { font-size: 8.5px; color: #64748b; margin-top: 2px; }

  .footer { margin-top: 50px; border-top: 1px solid #e2e8f0; padding-top: 6px;
            font-size: 7px; color: #94a3b8; text-align: center; }
</style>
</head>
<body>
<div class="page">

  <div class="header">
    <img src="{{ public_path('images/hpr_logo.png') }}" alt="Palma Real Hotel y Villas">
    <h2>Departamento de Recursos Humanos</h2>
    <p>La Ceiba, Atlántida, Honduras</p>
  </div>

  <div class="titulo">CONSTANCIA DE TRABAJO</div>

  <div class="cuerpo">
    <p>
      El Departamento de Recursos Humanos de <strong>Hotel Palma Real y Villas</strong> hace constar que
      <strong>{{ $emp->nombres }} {{ $emp->apellidos }}</strong>,
      identificado(a) con número de identidad <strong>{{ $emp->cedula ?? '—' }}</strong>,
      labora en esta empresa desde el
      <strong>{{ $fechaInicio->format('d') }} de {{ $meses[$fechaInicio->month - 1] }} de {{ $fechaInicio->format('Y') }}</strong>,
      desempeñando el puesto de <strong>{{ $emp->puesto?->nombre ?? '—' }}</strong>,
      con un salario mensual de <strong>{{ $simboloMoneda }} {{ number_format($salarioMensual, 2) }}</strong>.
    </p>
    <p>
      Se extiende la presente constancia a solicitud del(la) interesado(a), para los fines que estime
      convenientes, en la ciudad de La Ceiba, a los {{ now()->format('d') }} días del mes de
      {{ $meses[now()->month - 1] }} de {{ now()->format('Y') }}.
    </p>
  </div>

  <div class="firma-wrap">
    <div class="firma-linea"></div>
    <div class="firma-nombre">Gerente de Recursos Humanos</div>
    <div class="firma-cargo">Hotel Palma Real y Villas</div>
  </div>

  <div class="footer">
    Documento generado el {{ now()->format('d/m/Y H:i') }} · Sistema RRHH Hotel Palma Real
  </div>

</div>
</body>
</html>
