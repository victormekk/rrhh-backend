<?php

namespace App\Traits;

use App\Calculos\Feriados;
use Carbon\Carbon;

// Atajo para controladores; la lógica está en App\Calculos\Feriados (probada en tests/Unit).
trait TieneFeriados
{
    protected function feriadosDelAnio(int $anio): array
    {
        return Feriados::delAnio($anio);
    }

    // Nombre del feriado si la fecha cae en uno, o null si es un dia normal.
    protected function nombreFeriado(Carbon $fecha): ?string
    {
        return Feriados::nombre($fecha);
    }
}
