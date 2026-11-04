<?php

namespace App\Pdf;

// Círculos para los PDF (foto o iniciales), dibujados como imagen PNG con GD.
// DomPDF no centra bien texto dentro de un círculo ni recorta fotos en redondo,
// así que se entregan ya listos como data URI para usarlos en un <img>.
class AvatarCircular
{
    // Se dibuja al doble de tamaño y se reduce, para que el borde salga suave.
    private const ESCALA = 2;

    public static function iniciales(string $texto, string $colorHex, int $px = 120): string
    {
        $lado = $px * self::ESCALA;
        $img  = self::lienzoTransparente($lado);
        [$r, $g, $b] = sscanf(ltrim($colorHex, '#'), '%02x%02x%02x');
        imagefilledellipse($img, intdiv($lado, 2), intdiv($lado, 2), $lado - 2, $lado - 2, imagecolorallocate($img, $r, $g, $b));

        $fuente = base_path('vendor/dompdf/dompdf/lib/fonts/DejaVuSans-Bold.ttf');
        $texto  = mb_strtoupper($texto);
        $tamano = $lado * 0.36;
        $blanco = imagecolorallocate($img, 255, 255, 255);
        if (is_file($fuente) && function_exists('imagettfbbox')) {
            // Centrado exacto con la caja real del texto (no con el alto de línea).
            $caja = imagettfbbox($tamano, 0, $fuente, $texto);
            $ancho = $caja[2] - $caja[0];
            $alto  = $caja[1] - $caja[7];
            $x = intdiv($lado - $ancho, 2) - $caja[0];
            $y = intdiv($lado + $alto, 2) - $caja[1];
            imagettftext($img, $tamano, 0, $x, $y, $blanco, $fuente, $texto);
        } else {
            $ancho = imagefontwidth(5) * strlen($texto);
            imagestring($img, 5, intdiv($lado - $ancho, 2), intdiv($lado - imagefontheight(5), 2), $texto, $blanco);
        }

        return self::comoDataUri($img, $px);
    }

    // Foto recortada en círculo (centrada y cuadrada). null si no se puede leer.
    public static function foto(string $ruta, int $px = 120): ?string
    {
        $origen = @imagecreatefromstring((string) @file_get_contents($ruta));
        if (!$origen) {
            return null;
        }

        $lado = $px * self::ESCALA;
        $w = imagesx($origen);
        $h = imagesy($origen);
        $corte = min($w, $h);
        $cuadro = imagecreatetruecolor($lado, $lado);
        imagecopyresampled($cuadro, $origen, 0, 0, intdiv($w - $corte, 2), intdiv($h - $corte, 2), $lado, $lado, $corte, $corte);
        imagedestroy($origen);

        // Se copia solo lo que cae dentro del círculo sobre un fondo transparente.
        $img = self::lienzoTransparente($lado);
        $radio2 = ($lado / 2) ** 2;
        for ($y = 0; $y < $lado; $y++) {
            for ($x = 0; $x < $lado; $x++) {
                if (($x - $lado / 2 + 0.5) ** 2 + ($y - $lado / 2 + 0.5) ** 2 <= $radio2) {
                    imagesetpixel($img, $x, $y, imagecolorat($cuadro, $x, $y));
                }
            }
        }
        imagedestroy($cuadro);

        return self::comoDataUri($img, $px);
    }

    private static function lienzoTransparente(int $lado)
    {
        $img = imagecreatetruecolor($lado, $lado);
        imagealphablending($img, false);
        imagesavealpha($img, true);
        imagefill($img, 0, 0, imagecolorallocatealpha($img, 0, 0, 0, 127));
        imagealphablending($img, true);

        return $img;
    }

    private static function comoDataUri($img, int $px): string
    {
        $final = self::lienzoTransparente($px);
        imagealphablending($final, false);
        imagecopyresampled($final, $img, 0, 0, 0, 0, $px, $px, imagesx($img), imagesy($img));
        imagedestroy($img);

        ob_start();
        imagepng($final);
        imagedestroy($final);

        return 'data:image/png;base64,' . base64_encode(ob_get_clean());
    }
}
