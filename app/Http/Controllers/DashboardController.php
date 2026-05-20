<?php

namespace App\Http\Controllers;

use App\Models\CabeceraPlanilla;
use App\Models\DetallePlanilla;
use App\Models\Empleado;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function stats()
    {
        $hoy = Carbon::now();

        return response()->json([
            'empleados_total'   => Empleado::count(),
            'empleados_activos' => Empleado::whereHas('informacionLaboral',
                fn($q) => $q->where('estado', 'Activo'))->count(),
            'empleados_fijos'   => Empleado::whereHas('informacionLaboral',
                fn($q) => $q->where('estado', 'Activo')->where('tipo_contrato', 'Tiempo Completo'))->count(),
            'empleados_extras'  => Empleado::whereHas('informacionLaboral',
                fn($q) => $q->where('estado', 'Activo')->whereIn('tipo_contrato', ['Tiempo Parcial', 'Temporal', 'Por Obra']))->count(),
            'cumpleanos_mes'    => Empleado::whereNotNull('fecha_nacimiento')
                ->whereRaw('MONTH(fecha_nacimiento) = ?', [$hoy->month])
                ->whereHas('informacionLaboral', fn($q) => $q->where('estado', 'Activo'))
                ->count(),
        ]);
    }

    public function chartPlanillas()
    {
        $desde = Carbon::now()->subMonths(11)->startOfMonth();

        $rows = DetallePlanilla::select(
                DB::raw('YEAR(fecha_generada) as anio'),
                DB::raw('MONTH(fecha_generada) as mes'),
                'tipo_planilla',
                DB::raw('SUM(salario_neto) as total')
            )
            ->where('fecha_generada', '>=', $desde)
            ->whereIn('tipo_planilla', ['Fijos', 'Extras'])
            ->groupBy('anio', 'mes', 'tipo_planilla')
            ->get();

        $resultado = [];
        for ($i = 11; $i >= 0; $i--) {
            $m     = Carbon::now()->subMonths($i);
            $anio  = $m->year;
            $mesN  = $m->month;
            $label = ucfirst($m->locale('es')->isoFormat('MMM YYYY'));

            $fijos  = $rows->where('anio', $anio)->where('mes', $mesN)->where('tipo_planilla', 'Fijos')->first();
            $extras = $rows->where('anio', $anio)->where('mes', $mesN)->where('tipo_planilla', 'Extras')->first();

            $resultado[] = [
                'label'  => $label,
                'fijos'  => (float) ($fijos->total  ?? 0),
                'extras' => (float) ($extras->total ?? 0),
            ];
        }

        return response()->json($resultado);
    }
}
