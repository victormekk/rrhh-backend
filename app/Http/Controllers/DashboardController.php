<?php

namespace App\Http\Controllers;

use App\Models\CabeceraPlanilla;
use App\Models\Departamento;
use App\Models\Empleado;
use App\Models\Incidencia;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class DashboardController extends Controller
{
    public function stats(Request $request)
    {
        $inicioMes = Carbon::now()->startOfMonth();

        return response()->json([
            'empleados'      => Empleado::whereHas('informacionLaboral', fn($q) => $q->where('estado', 'Activo'))->count(),
            'departamentos'  => Departamento::where('estado', 'Activo')->count(),
            'incidencias'    => Incidencia::where('fecha_incidencia', '>=', $inicioMes)->count(),
            'planillas'      => CabeceraPlanilla::where('estado', 'Activo')->count(),
        ]);
    }
}
