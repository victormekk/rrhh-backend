<?php

namespace App\Calculos;

use Carbon\Carbon;

// Feriados nacionales de Honduras. Sin base de datos: se prueba en tests/Unit/FeriadosTest.php.
class Feriados
{
    // Feriados de un año. Semana Santa se calcula a partir del Domingo de Pascua
    // (easter_date, algoritmo de Meeus/Jones/Butcher) y el Feriado Morazánico son
    // los 3 días (miércoles, jueves, viernes) que empiezan el primer miércoles de
    // octubre. El resto de fechas son fijas según el Código del Trabajo de Honduras.
    public static function delAnio(int $anio): array
    {
        $pascua = Carbon::createFromTimestamp(easter_date($anio))->startOfDay();

        $primerMiercolesOctubre = Carbon::create($anio, 10, 1)->startOfDay();
        while ($primerMiercolesOctubre->dayOfWeek !== Carbon::WEDNESDAY) {
            $primerMiercolesOctubre->addDay();
        }

        return [
            ['fecha' => Carbon::create($anio, 1, 1),                'nombre' => 'Año Nuevo'],
            ['fecha' => $pascua->copy()->subDays(3),                'nombre' => 'Jueves Santo'],
            ['fecha' => $pascua->copy()->subDays(2),                'nombre' => 'Viernes Santo'],
            ['fecha' => $pascua->copy()->subDays(1),                'nombre' => 'Sábado de Gloria'],
            ['fecha' => Carbon::create($anio, 4, 14),               'nombre' => 'Día de las Américas'],
            ['fecha' => Carbon::create($anio, 5, 1),                'nombre' => 'Día del Trabajo'],
            ['fecha' => Carbon::create($anio, 9, 15),               'nombre' => 'Día de la Independencia'],
            ['fecha' => $primerMiercolesOctubre->copy(),            'nombre' => 'Feriado Morazánico'],
            ['fecha' => $primerMiercolesOctubre->copy()->addDay(),  'nombre' => 'Feriado Morazánico'],
            ['fecha' => $primerMiercolesOctubre->copy()->addDays(2),'nombre' => 'Feriado Morazánico'],
            ['fecha' => Carbon::create($anio, 12, 25),              'nombre' => 'Navidad'],
        ];
    }

    // Nombre del feriado si la fecha cae en uno, o null si es un día normal.
    public static function nombre(Carbon $fecha): ?string
    {
        foreach (self::delAnio($fecha->year) as $feriado) {
            if ($feriado['fecha']->isSameDay($fecha)) {
                return $feriado['nombre'];
            }
        }
        return null;
    }

    // Siguiente día hábil después de una fecha: salta domingos y feriados.
    public static function siguienteDiaHabil(Carbon $fecha): Carbon
    {
        $dia = $fecha->copy()->addDay();
        while ($dia->dayOfWeek === Carbon::SUNDAY || self::nombre($dia)) {
            $dia->addDay();
        }
        return $dia;
    }
}
