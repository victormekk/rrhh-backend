<?php

namespace App\Traits;

use Carbon\Carbon;

trait TieneFeriados
{
    // Feriados nacionales de Honduras para un anio dado. Semana Santa se calcula
    // a partir del Domingo de Pascua (easter_date, algoritmo de Meeus/Jones/Butcher)
    // y el Feriado Morazanico son los 3 dias (miercoles, jueves, viernes) de la
    // primera semana completa de octubre que contiene un miercoles. El resto de
    // fechas son fijas segun el Codigo del Trabajo de Honduras.
    protected function feriadosDelAnio(int $anio): array
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

    // Nombre del feriado si la fecha cae en uno, o null si es un dia normal.
    protected function nombreFeriado(Carbon $fecha): ?string
    {
        foreach ($this->feriadosDelAnio($fecha->year) as $feriado) {
            if ($feriado['fecha']->isSameDay($fecha)) {
                return $feriado['nombre'];
            }
        }
        return null;
    }
}
