<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<style>
  /* :not(html):not(body): ver log-sistema/pdf.blade.php (bug de dompdf con el margen del @page). */
  *:not(html):not(body) { box-sizing: border-box; margin: 0; padding: 0; }
  @page { size: letter portrait; margin: 0.7cm 1.27cm; }
  body { font-family: DejaVu Sans, sans-serif; color: #1e293b; }

  /* Colores de la marca: marrón #3b2b16, dorado #b9921a y los de la mariposa (rojo, naranja, amarillo). */
  .encabezado { width: 100%; border-collapse: collapse; }
  .encabezado td { vertical-align: middle; }
  .hotel   { font-size: 8px; font-weight: bold; color: #b9921a; text-transform: uppercase; letter-spacing: 2px; }
  .titulo  { font-size: 22px; font-weight: bold; color: #3b2b16; margin-top: 3px; }
  .titulo span { color: #b9921a; }
  .lema    { font-size: 10px; color: #8a6d10; margin-top: 4px; }
  .total   {
    display: inline-block; margin-top: 7px; padding: 3px 10px; border-radius: 10px;
    background: #3b2b16; color: #ffffff; font-size: 8px; font-weight: bold; letter-spacing: 0.4px;
  }

  .franja { width: 100%; border-collapse: collapse; margin: 9px 0 8px; }
  .franja td { height: 4px; }

  /* Tarjetas en filas de 3. Tabla en vez de grid/flex: dompdf no los soporta. */
  table.tarjetas { width: 100%; border-collapse: separate; border-spacing: 8px 7px; margin: 0 -8px; table-layout: fixed; }
  tr { page-break-inside: avoid; }
  td.tarjeta {
    width: 33.33%; vertical-align: top; text-align: center; background: #ffffff;
    border: 1px solid #ece6d6; border-radius: 12px; padding: 0 10px 9px;
  }
  td.vacia { border: none; background: none; }
  .acento  { height: 4px; border-radius: 0 0 4px 4px; margin: 0 22px 8px; }
  .avatar  { width: 44px; height: 44px; }
  .nombre  { font-size: 9px; font-weight: bold; color: #1e293b; line-height: 1.35; margin-top: 5px; }
  .depto   { font-size: 7px; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.5px; margin-top: 3px; }
  .fecha   {
    display: inline-block; margin-top: 6px; padding: 3px 10px; border-radius: 10px;
    background: #f8f2df; border: 1px solid #e9d6a0; color: #6b5310; font-size: 8px; font-weight: bold;
  }

  .vacio { text-align: center; padding: 50px 20px; color: #94a3b8; font-size: 11px; border: 1px dashed #e9d6a0; border-radius: 12px; }

  .cierre { margin-top: 6px; text-align: center; page-break-inside: avoid; }
  .cierre .mensaje { font-size: 11px; font-weight: bold; color: #3b2b16; }
  .cierre .mensaje span { color: #b9921a; }
  .cierre .firma { font-size: 8px; color: #8a6d10; margin-top: 3px; }
  .cierre .generado { font-size: 7px; color: #94a3b8; margin-top: 8px; }
</style>
</head>
<body>

  <table class="encabezado">
    <tr>
      <td style="width: 3.6cm;">
        <img src="{{ public_path('images/hpr_logo.png') }}" alt="Hotel y Villas Palma Real" style="width: 3.4cm; height: auto;">
      </td>
      <td style="padding-left: 14px;">
        <div class="hotel">Hotel y Villas Palma Real</div>
        <div class="titulo">Cumpleañeros de <span>{{ $nombreMes }}</span></div>
        <div class="lema">&#9733; ¡Celebramos a quienes hacen grande a nuestra familia! &#9733;</div>
        <div class="total">{{ $lista->count() }} {{ $lista->count() === 1 ? 'cumpleañero' : 'cumpleañeros' }}</div>
      </td>
    </tr>
  </table>

  <table class="franja">
    <tr>
      <td style="background: #c0101a;"></td>
      <td style="background: #e8892f;"></td>
      <td style="background: #f5d92a;"></td>
      <td style="background: #b9921a;"></td>
    </tr>
  </table>

  @if($lista->isEmpty())
    <div class="vacio">Ningún empleado activo cumple años en {{ $nombreMes }}.</div>
  @else
    <table class="tarjetas">
      @foreach($lista->chunk(3) as $fila)
        {{-- Última fila incompleta: si queda una sola tarjeta va al centro. --}}
        @php($antes = $fila->count() === 1 ? 1 : 0)
        @php($despues = 3 - $fila->count() - $antes)
        <tr>
          @for($i = 0; $i < $antes; $i++)
            <td class="tarjeta vacia"></td>
          @endfor
          @foreach($fila as $emp)
            <td class="tarjeta">
              <div class="acento" style="background: {{ $emp['color'] }};"></div>
              <img class="avatar" src="{{ $emp['avatar'] }}" alt="">
              <div class="nombre">{{ $emp['nombres'] }} {{ $emp['apellidos'] }}</div>
              <div class="depto">{{ $emp['departamento'] }}</div>
              <div class="fecha">{{ $emp['dia'] }} de {{ $nombreMes }}</div>
            </td>
          @endforeach
          @for($i = 0; $i < $despues; $i++)
            <td class="tarjeta vacia"></td>
          @endfor
        </tr>
      @endforeach
    </table>
  @endif

  <div class="cierre">
    <div class="mensaje"><span>&#9733;</span> ¡Muchas felicidades en su día! <span>&#9733;</span></div>
    <div class="firma">Con cariño y gratitud · Hotel y Villas Palma Real</div>
    <div class="generado">Generado el {{ now()->format('d/m/Y') }}</div>
  </div>

</body>
</html>
