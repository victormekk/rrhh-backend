<?php

namespace App\Calculos;

use Carbon\Carbon;

class Fechas
{
    // Aniversario de una fecha (inicio laboral o nacimiento) en un año dado.
    // Un 29 de febrero se toma como 28 de febrero en los años no bisiestos.
    // Se prueba en tests/Unit/FechasTest.php.
    public static function aniversarioEn(Carbon $fecha, int $anio): Carbon
    {
        $dia = ($fecha->month === 2 && $fecha->day === 29 && !Carbon::create($anio, 1, 1)->isLeapYear())
            ? 28
            : $fecha->day;

        return Carbon::create($anio, $fecha->month, $dia)->startOfDay();
    }

    public static function es29DeFebrero($fecha): bool
    {
        if (!$fecha) return false;
        $f = $fecha instanceof Carbon ? $fecha : Carbon::parse($fecha);

        return $f->month === 2 && $f->day === 29;
    }
}
