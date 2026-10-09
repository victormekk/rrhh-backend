{{--
  Encabezado común de los reportes PDF: logo de 4 cm a la izquierda y, pegados a su
  derecha y alineados a la izquierda, el título y los datos del reporte.
  Estilos en línea para que DomPDF los respete. La tabla no lleva width: 100% (DomPDF
  repartiría el ancho entre las dos celdas y separaría el texto del logo); la línea
  inferior va en el contenedor, que sí ocupa todo el ancho.
  Uso: <x-pdf-encabezado titulo="..." subtitulo="...">líneas de datos</x-pdf-encabezado>
--}}
@props(['titulo', 'subtitulo' => null])

<div style="border-bottom: 2px solid #3b2b16; padding-bottom: 8px; margin-bottom: 12px; font-family: 'DejaVu Sans', sans-serif;">
  <table style="width: auto; border: none; border-collapse: collapse; background: none; margin: 0;">
    <tr>
      <td style="width: 4cm; vertical-align: middle; text-align: left; padding: 0; border: none; background: none;">
        <img src="{{ public_path('images/hpr_logo.png') }}" alt="Hotel y Villas Palma Real" style="width: 4cm; height: auto; display: block;">
      </td>
      <td style="vertical-align: middle; text-align: left; padding: 0 0 0 12px; border: none; background: none;">
        <div style="font-family: 'DejaVu Sans', sans-serif; font-size: 15px; font-weight: bold; color: #3b2b16; letter-spacing: 0.4px;">{{ $titulo }}</div>
        @if($subtitulo)
          <div style="font-family: 'DejaVu Sans', sans-serif; font-size: 9px; color: #64748b; margin-top: 3px;">{{ $subtitulo }}</div>
        @endif
        @if(trim($slot) !== '')
          <div style="font-family: 'DejaVu Sans', sans-serif; font-size: 8.5px; color: #8a6d10; margin-top: 4px; font-weight: bold; line-height: 1.5;">{{ $slot }}</div>
        @endif
      </td>
    </tr>
  </table>
</div>
