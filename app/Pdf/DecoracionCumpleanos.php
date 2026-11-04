<?php

namespace App\Pdf;

// Adornos del PDF de cumpleaños (globos, banderines y confeti), dibujados con GD
// como PNG transparentes: DomPDF no dibuja figuras con CSS. Se dibujan al triple
// de tamaño y se reducen para que los bordes salgan suaves.
class DecoracionCumpleanos
{
    private const ESCALA = 3;

    // Colores de la marca y de la mariposa, más algunos alegres.
    public const COLORES = ['#c0101a', '#e8892f', '#f5d92a', '#b9921a', '#8b5cf6', '#06b6d4', '#ec4899', '#10b981'];

    // Ramo de 3 globos con sus hilos atados abajo al centro: el del medio más alto
    // (se dibuja primero, queda detrás) y los de los lados un poco más abajo.
    public static function globos(array $colores, int $ancho = 150, int $alto = 190): string
    {
        $e = self::ESCALA;
        $W = $ancho * $e;
        $H = $alto * $e;
        $img = self::lienzo($W, $H);
        $rx = (int) ($W * 0.2);
        $ry = (int) ($rx * 1.22);
        $arriba = (int) ($ry * 1.08);

        [$izq, $centro, $der] = array_pad(array_values($colores), 3, self::COLORES[0]);
        foreach ([[$centro, 0.5, 0], [$izq, 0.27, 0.22], [$der, 0.73, 0.22]] as [$hex, $fx, $baja]) {
            self::globo($img, (int) ($W * $fx), $arriba + (int) ($ry * $baja), $rx, $ry, $hex, $W / 2, $H - 2 * $e);
        }

        return self::dataUri($img, $ancho, $alto);
    }

    // Globo pequeño para acompañar la fecha.
    public static function globito(string $hex, int $px = 14): string
    {
        $e = self::ESCALA;
        $img = self::lienzo($px * $e, (int) ($px * 1.6 * $e));
        $rx = (int) ($px * $e * 0.45);
        $ry = (int) ($rx * 1.2);
        self::globo($img, (int) ($px * $e / 2), $ry + 2 * $e, $rx, $ry, $hex, $px * $e / 2, $px * 1.6 * $e - 1);

        return self::dataUri($img, $px, (int) ($px * 1.6));
    }

    // Cordón con banderines triangulares de colores, de lado a lado.
    public static function banderines(int $ancho = 760, int $alto = 46, int $cantidad = 19): string
    {
        $e = self::ESCALA;
        $W = $ancho * $e;
        $H = $alto * $e;
        $img = self::lienzo($W, $H);
        $curva = fn (float $x) => $H * 0.12 + sin(M_PI * $x / $W) * $H * 0.18;

        $cordon = self::color($img, '#8a6d10');
        imagesetthickness($img, 2 * $e);
        for ($x = 0; $x < $W; $x += 4) {
            imageline($img, $x, (int) $curva($x), $x + 4, (int) $curva($x + 4), $cordon);
        }

        $paso = $W / $cantidad;
        for ($i = 0; $i < $cantidad; $i++) {
            $x1 = $i * $paso + $paso * 0.08;
            $x2 = ($i + 1) * $paso - $paso * 0.08;
            $xm = ($x1 + $x2) / 2;
            $c = self::color($img, self::COLORES[$i % count(self::COLORES)]);
            imagefilledpolygon($img, [
                (int) $x1, (int) $curva($x1),
                (int) $x2, (int) $curva($x2),
                (int) $xm, (int) ($curva($xm) + $H * 0.62),
            ], $c);
        }

        return self::dataUri($img, $ancho, $alto);
    }

    // Confeti suave para el fondo de la hoja (siempre el mismo dibujo).
    public static function confeti(int $ancho = 816, int $alto = 1056): string
    {
        $e = 2;
        $img = self::lienzo($ancho * $e, $alto * $e);
        mt_srand(2026);
        for ($i = 0; $i < 170; $i++) {
            [$r, $g, $b] = self::rgb(self::COLORES[$i % count(self::COLORES)]);
            $c = imagecolorallocatealpha($img, $r, $g, $b, 92); // muy tenue
            $x = mt_rand(0, $ancho * $e);
            $y = mt_rand(0, $alto * $e);
            $t = mt_rand(5, 11) * $e;
            if ($i % 3 === 0) {
                imagefilledellipse($img, $x, $y, $t, $t, $c);
            } else {
                // Tirita girada.
                $ang = mt_rand(0, 314) / 100;
                $dx = cos($ang) * $t;
                $dy = sin($ang) * $t;
                $ox = -sin($ang) * $t * 0.35;
                $oy = cos($ang) * $t * 0.35;
                imagefilledpolygon($img, [
                    (int) ($x - $dx - $ox), (int) ($y - $dy - $oy),
                    (int) ($x + $dx - $ox), (int) ($y + $dy - $oy),
                    (int) ($x + $dx + $ox), (int) ($y + $dy + $oy),
                    (int) ($x - $dx + $ox), (int) ($y - $dy + $oy),
                ], $c);
            }
        }
        mt_srand();

        return self::dataUri($img, $ancho, $alto);
    }

    private static function globo($img, int $cx, int $cy, int $rx, int $ry, string $hex, float $hiloX, float $hiloY): void
    {
        // Hilo ondulado desde el nudo hasta el punto de amarre.
        $hilo = self::color($img, '#9a8a6a');
        imagesetthickness($img, max(1, (int) ($rx * 0.05)));
        $x0 = $cx;
        $y0 = $cy + $ry;
        $pasos = 24;
        for ($s = 0; $s < $pasos; $s++) {
            $t1 = $s / $pasos;
            $t2 = ($s + 1) / $pasos;
            $p = fn ($t) => [
                $x0 + ($hiloX - $x0) * $t + sin($t * M_PI * 3) * $rx * 0.12,
                $y0 + ($hiloY - $y0) * $t,
            ];
            [$ax, $ay] = $p($t1);
            [$bx, $by] = $p($t2);
            imageline($img, (int) $ax, (int) $ay, (int) $bx, (int) $by, $hilo);
        }

        $color = self::color($img, $hex);
        // Nudo
        imagefilledpolygon($img, [
            $cx, $cy + $ry - (int) ($ry * 0.04),
            $cx - (int) ($rx * 0.14), $cy + $ry + (int) ($ry * 0.12),
            $cx + (int) ($rx * 0.14), $cy + $ry + (int) ($ry * 0.12),
        ], $color);
        // Cuerpo
        imagefilledellipse($img, $cx, $cy, $rx * 2, $ry * 2, $color);
        // Brillo
        imagefilledellipse($img, $cx - (int) ($rx * 0.38), $cy - (int) ($ry * 0.42), (int) ($rx * 0.42), (int) ($ry * 0.55),
            imagecolorallocatealpha($img, 255, 255, 255, 70));
    }

    private static function lienzo(int $w, int $h)
    {
        $img = imagecreatetruecolor($w, $h);
        imagealphablending($img, false);
        imagesavealpha($img, true);
        imagefill($img, 0, 0, imagecolorallocatealpha($img, 0, 0, 0, 127));
        imagealphablending($img, true);

        return $img;
    }

    private static function rgb(string $hex): array
    {
        return sscanf(ltrim($hex, '#'), '%02x%02x%02x');
    }

    private static function color($img, string $hex): int
    {
        [$r, $g, $b] = self::rgb($hex);

        return imagecolorallocate($img, $r, $g, $b);
    }

    private static function dataUri($img, int $w, int $h): string
    {
        $final = imagecreatetruecolor($w, $h);
        imagealphablending($final, false);
        imagesavealpha($final, true);
        imagefill($final, 0, 0, imagecolorallocatealpha($final, 0, 0, 0, 127));
        imagecopyresampled($final, $img, 0, 0, 0, 0, $w, $h, imagesx($img), imagesy($img));
        imagedestroy($img);

        ob_start();
        imagepng($final);
        imagedestroy($final);

        return 'data:image/png;base64,' . base64_encode(ob_get_clean());
    }
}
