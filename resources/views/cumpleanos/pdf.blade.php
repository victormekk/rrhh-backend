<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<style>
  /* :not(html):not(body): ver log-sistema/pdf.blade.php (bug de dompdf con el margen del @page). */
  *:not(html):not(body) { box-sizing: border-box; margin: 0; padding: 0; }
  @page { size: letter portrait; margin: 0.6cm 1.1cm 0.7cm; }
  body { font-family: DejaVu Sans, sans-serif; color: #1e293b; }

  /* Confeti de fondo en todas las hojas (position: fixed se repite en cada página). */
  .confeti { position: fixed; top: -0.6cm; left: -1.1cm; width: 21.59cm; height: 27.94cm; z-index: -1; }

  /* Colores de la marca: marrón #3b2b16, dorado #b9921a y los de la mariposa. */
  .banderines { width: 100%; height: 1.05cm; display: block; }
  .encabezado { width: 100%; border-collapse: collapse; margin-top: 2px; }
  .encabezado td { vertical-align: middle; text-align: center; }
  .feliz   { font-size: 27px; font-weight: bold; color: #c0101a; letter-spacing: 0.5px; margin-top: 1px; }
  .mes     { font-size: 15px; font-weight: bold; color: #3b2b16; margin-top: 2px; }
  .mes span { color: #b9921a; }
  .lema    { font-size: 9.5px; color: #8a6d10; margin-top: 4px; }
  .total   {
    display: inline-block; margin-top: 6px; padding: 3px 12px; border-radius: 10px;
    background: #3b2b16; color: #ffffff; font-size: 8px; font-weight: bold; letter-spacing: 0.4px;
  }

  /* Tarjetas en filas de 4 (caben hasta 20 en una hoja). Tabla en vez de grid/flex: dompdf no los soporta. */
  table.tarjetas { width: 100%; border-collapse: separate; border-spacing: 8px 6px; margin: 2px -8px 0; table-layout: fixed; }
  tr { page-break-inside: avoid; }
  td.tarjeta {
    width: 25%; vertical-align: top; text-align: center; background: #ffffff;
    border: 1px solid #ece6d6; border-radius: 12px; padding: 0 7px 7px;
  }
  td.vacia { border: none; background: none; }
  .acento  { height: 4px; border-radius: 0 0 4px 4px; margin: 0 16px 6px; }
  .avatar  { width: 38px; height: 38px; }
  .nombre  { font-size: 8.5px; font-weight: bold; color: #1e293b; line-height: 1.3; margin-top: 4px; }
  .depto   { font-size: 7px; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.5px; margin-top: 3px; }
  .fecha   {
    display: inline-block; margin-top: 5px; padding: 2px 10px 3px 7px; border-radius: 10px;
    background: #f8f2df; border: 1px solid #e9d6a0; color: #6b5310; font-size: 8px; font-weight: bold;
  }
  .fecha img { width: 8px; height: 13px; vertical-align: middle; margin-right: 3px; }

  .vacio { text-align: center; padding: 50px 20px; color: #94a3b8; font-size: 11px; border: 1px dashed #e9d6a0; border-radius: 12px; background: #ffffff; }

  .cierre { margin-top: 6px; text-align: center; page-break-inside: avoid; }
  .cierre .mensaje { font-size: 12px; font-weight: bold; color: #c0101a; }
  .cierre .mensaje span { color: #b9921a; }
  .cierre .firma { font-size: 8px; color: #8a6d10; margin-top: 3px; }
</style>
</head>
<body>

  <div class="confeti"><img src="{{ $deco['confeti'] }}" alt="" style="width: 100%; height: 100%;"></div>

  <img class="banderines" src="{{ $deco['banderines'] }}" alt="">

  <table class="encabezado">
    <tr>
      <td style="width: 3.4cm;"><img src="{{ $deco['globosIzq'] }}" alt="" style="width: 2.5cm; height: auto;"></td>
      <td>
        <img src="{{ public_path('images/hpr_logo.png') }}" alt="Hotel y Villas Palma Real" style="width: 2.3cm; height: auto;">
        <div class="feliz">¡Feliz Cumpleaños!</div>
        <div class="mes">Cumpleañeros de <span>{{ $nombreMes }}</span></div>
        <div class="lema">&#9733; ¡Celebramos a quienes hacen grande a nuestra familia! &#9733;</div>
        <div class="total">{{ $lista->count() }} {{ $lista->count() === 1 ? 'cumpleañero' : 'cumpleañeros' }}</div>
      </td>
      <td style="width: 3.4cm;"><img src="{{ $deco['globosDer'] }}" alt="" style="width: 2.5cm; height: auto;"></td>
    </tr>
  </table>

  @if($lista->isEmpty())
    <div class="vacio">Ningún empleado activo cumple años en {{ $nombreMes }}.</div>
  @else
    <table class="tarjetas">
      @foreach($lista->chunk(4) as $fila)
        {{-- Última fila incompleta: las tarjetas que quedan van al centro. --}}
        @php($antes = intdiv(4 - $fila->count(), 2))
        @php($despues = 4 - $fila->count() - $antes)
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
              <div class="fecha"><img src="{{ $emp['globito'] }}" alt="">{{ $emp['dia'] }} de {{ $nombreMes }}</div>
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
  </div>

</body>
</html>
