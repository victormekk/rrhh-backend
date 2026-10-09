<?php

namespace App\Http\Controllers;

use App\Calculos\Fechas;
use App\Models\Empleado;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class CumpleanosController extends Controller
{
    public function index(Request $request)
    {
        $mes = (int) ($request->mes ?? Carbon::now()->month);
        $hoy = Carbon::now();

        $empleados = Empleado::with(['departamento:id,nombre'])
            ->whereNotNull('fecha_nacimiento')
            ->whereHas('informacionLaboral', fn($q) => $q->where('estado', 'Activo'))
            ->whereRaw('MONTH(fecha_nacimiento) = ?', [$mes])
            ->orderByRaw('DAY(fecha_nacimiento)')
            ->get()
            ->map(function ($emp) use ($hoy) {
                $nac = $emp->fecha_nacimiento;
                // Quien nació un 29 de febrero celebra el 28 en años no bisiestos.
                $cumpleEsteAnio = Fechas::aniversarioEn($nac, $hoy->year);
                $esHoy = $cumpleEsteAnio->isSameDay($hoy);
                $edad  = $hoy->year - $nac->year;

                $proxCumple = $cumpleEsteAnio->copy();
                if ($proxCumple->lt($hoy->copy()->startOfDay()) && !$esHoy) {
                    $proxCumple = Fechas::aniversarioEn($nac, $hoy->year + 1);
                }
                $diasPara = $esHoy ? 0 : (int) $hoy->copy()->startOfDay()->diffInDays($proxCumple);

                return [
                    'id'               => $emp->id,
                    'nombres'          => $emp->nombres,
                    'apellidos'        => $emp->apellidos,
                    'genero'           => $emp->genero,
                    'foto_url'         => $emp->foto_url,
                    'departamento'     => $emp->departamento?->nombre ?? '—',
                    'fecha_nacimiento' => $nac->format('Y-m-d'),
                    'fecha_celebracion' => $cumpleEsteAnio->format('Y-m-d'),
                    'dia'              => $nac->day,
                    'edad_cumple'      => $edad,
                    'es_hoy'           => $esHoy,
                    'dias_para'        => $diasPara,
                ];
            });

        return response()->json($empleados);
    }
}
