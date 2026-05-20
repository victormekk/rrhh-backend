<?php

namespace App\Http\Controllers;

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
                $nac   = $emp->fecha_nacimiento;
                $esHoy = $nac->format('m-d') === $hoy->format('m-d');
                $edad  = $hoy->year - $nac->year;

                $proxCumple = Carbon::create($hoy->year, $nac->month, $nac->day);
                if ($proxCumple->lt($hoy) && !$esHoy) {
                    $proxCumple->addYear();
                }
                $diasPara = $esHoy ? 0 : (int) $hoy->diffInDays($proxCumple);

                return [
                    'id'               => $emp->id,
                    'nombres'          => $emp->nombres,
                    'apellidos'        => $emp->apellidos,
                    'foto_url'         => $emp->foto_url,
                    'departamento'     => $emp->departamento?->nombre ?? '—',
                    'fecha_nacimiento' => $nac->format('Y-m-d'),
                    'dia'              => $nac->day,
                    'edad_cumple'      => $edad,
                    'es_hoy'           => $esHoy,
                    'dias_para'        => $diasPara,
                ];
            });

        return response()->json($empleados);
    }
}
