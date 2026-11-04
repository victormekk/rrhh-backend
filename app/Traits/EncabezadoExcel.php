<?php

namespace App\Traits;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Shared\Font;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

// Encabezado común de los Excel, igual que los reportes PDF: el logo a la izquierda
// (columna A, filas 1 a 3) y, a su derecha y alineadas a la izquierda, las líneas de título.
trait EncabezadoExcel
{
    // Logo con fondo blanco en un espacio de 6,94 cm × 3,09 cm (a 96 ppp: 262 × 117 px), en la
    // esquina superior izquierda. La imagen ya trae esa proporción (el logo va centrado sobre
    // blanco), así que no se deforma.
    private const LOGO_ANCHO_PX    = 262;
    private const LOGO_ALTO_PX     = 117;
    private const ALTO_FILA_PT     = 30;   // filas 1 a 3: 3 × 30 pt = 120 px ≥ alto del logo
    private const COLUMNA_A_MINIMA = 37.5; // ancho de columna (caracteres) ≈ 267 px ≥ ancho del logo

    // $lineas: [[texto, tamaño de letra o null], ...] — hasta 3 líneas (filas 1, 2 y 3).
    protected function encabezadoExcel(Worksheet $sheet, string $ultimaCol, array $lineas): void
    {
        $logo = new Drawing();
        $logo->setName('Logo');
        $logo->setDescription('Hotel y Villas Palma Real');
        $logo->setPath(public_path('images/hpr_logo_fondo_blanco.png'));
        $logo->setResizeProportional(false);
        $logo->setWidth(self::LOGO_ANCHO_PX);
        $logo->setHeight(self::LOGO_ALTO_PX);
        $logo->setCoordinates('A1');
        $logo->setOffsetX(0);
        $logo->setOffsetY(0);
        $logo->setWorksheet($sheet);

        // Los títulos van de B a la última columna (al menos hasta D, para que el texto
        // tenga espacio aunque el reporte tenga pocas columnas). Al estar combinadas,
        // no afectan el ancho automático de las columnas.
        $hasta = Coordinate::columnIndexFromString($ultimaCol) < 4 ? 'D' : $ultimaCol;

        foreach ([1, 2, 3] as $fila) {
            $sheet->getRowDimension($fila)->setRowHeight(self::ALTO_FILA_PT);
        }

        foreach (array_values($lineas) as $i => [$texto, $tam]) {
            $fila = $i + 1;
            $sheet->mergeCells("B{$fila}:{$hasta}{$fila}");
            $sheet->setCellValue("B{$fila}", $texto);
            $estilo = $sheet->getStyle("B{$fila}");
            $estilo->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT)->setVertical(Alignment::VERTICAL_CENTER)->setIndent(1);
            if ($tam) {
                $estilo->getFont()->setBold(true)->setSize($tam)->getColor()->setRGB('3B2B16');
            } else {
                $estilo->getFont()->setBold(true)->setSize(9)->getColor()->setRGB('8A6D10');
            }
        }
    }

    // Llamar cuando la hoja ya tiene todos sus datos (antes de guardar): si el ancho
    // automático de la columna A queda más angosto que el logo, se ensancha. Además,
    // con los anchos ya calculados, alarga las celdas combinadas de los títulos si el
    // texto no cabe (en reportes de pocas columnas, como el archivo de pago, el título
    // quedaba cortado porque una celda combinada no deja que el texto se desborde).
    protected function anchoColumnaLogo(Worksheet $sheet): void
    {
        $sheet->calculateColumnWidths();
        $columna = $sheet->getColumnDimension('A');
        if ($columna->getWidth() < self::COLUMNA_A_MINIMA) {
            $columna->setAutoSize(false)->setWidth(self::COLUMNA_A_MINIMA);
        }

        $this->ajustarTitulosEncabezado($sheet);
    }

    // Extiende hacia la derecha (solo alarga, nunca acorta) las combinaciones B{fila}:X{fila}
    // de las líneas de título hasta que la suma de anchos de columna alcance para el texto
    // más largo; las tres líneas quedan combinadas hasta la misma columna.
    private function ajustarTitulosEncabezado(Worksheet $sheet): void
    {
        $anchoDefecto = $sheet->getDefaultColumnDimension()->getWidth();
        $anchoDefecto = $anchoDefecto > 0 ? $anchoDefecto : 8.43; // ancho estándar de Excel
        $dimensiones  = $sheet->getColumnDimensions();
        $anchoDe = function (int $i) use ($dimensiones, $anchoDefecto): float {
            $col = Coordinate::stringFromColumnIndex($i);
            $w   = isset($dimensiones[$col]) ? $dimensiones[$col]->getWidth() : -1;
            return $w > 0 ? $w : $anchoDefecto;
        };

        $rangos    = [];
        $necesario = 0;
        foreach ([1, 2, 3] as $fila) {
            $rango = collect(array_keys($sheet->getMergeCells()))
                ->first(fn ($r) => str_starts_with($r, "B{$fila}:"));
            $texto = (string) $sheet->getCell("B{$fila}")->getValue();
            if (!$rango || $texto === '') {
                continue;
            }
            $rangos[$fila] = $rango;

            $estilo    = $sheet->getStyle("B{$fila}");
            $necesario = max($necesario, Font::calculateColumnWidth(
                $estilo->getFont(), $texto, 0, $sheet->getParent()?->getDefaultStyle()->getFont(),
                false, (int) $estilo->getAlignment()->getIndent()
            ) * 1.15); // margen: el cálculo aproximado no considera negrita ni mayúsculas
        }
        if (!$rangos) {
            return;
        }

        $fin = max(array_map(
            fn ($r) => Coordinate::columnIndexFromString(preg_replace('/\d+$/', '', explode(':', $r)[1])),
            $rangos
        ));
        $nuevoFin  = 2;
        $acumulado = $anchoDe(2);
        while ($acumulado < $necesario || $nuevoFin < $fin) {
            $nuevoFin++;
            $acumulado += $anchoDe($nuevoFin);
        }

        $hasta = Coordinate::stringFromColumnIndex($nuevoFin);
        foreach ($rangos as $fila => $rango) {
            if ($rango !== "B{$fila}:{$hasta}{$fila}") {
                $sheet->unmergeCells($rango);
                $sheet->mergeCells("B{$fila}:{$hasta}{$fila}");
            }
        }
    }
}
