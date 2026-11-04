<?php

namespace App\Http\Controllers;

use App\Calculos\Fechas;
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
                fn($q) => $q->where('estado', 'Activo')->where('tipo_contrato', 'Fijo'))->count(),
            'empleados_extras'  => Empleado::whereHas('informacionLaboral',
                fn($q) => $q->where('estado', 'Activo')->where('tipo_contrato', 'Extra'))->count(),
            'empleados_masculinos' => Empleado::where('genero', 'Masculino')
                ->whereHas('informacionLaboral', fn($q) => $q->where('estado', 'Activo'))->count(),
            'empleados_femeninos'  => Empleado::where('genero', 'Femenino')
                ->whereHas('informacionLaboral', fn($q) => $q->where('estado', 'Activo'))->count(),
            'cumpleanos_mes'    => Empleado::whereNotNull('fecha_nacimiento')
                ->whereRaw('MONTH(fecha_nacimiento) = ?', [$hoy->month])
                ->whereHas('informacionLaboral', fn($q) => $q->where('estado', 'Activo'))
                ->count(),
        ]);
    }

    // Control de jubilaciones (60 y 65 años): empleados activos desde los 59 años,
    // con la fecha en que cumplen 60 y 65. Los más próximos a un cumpleaños clave van primero.
    public function jubilaciones()
    {
        $hoy = Carbon::today();

        return response()->json(
            Empleado::with(['departamento:id,nombre', 'cargo:id,nombre'])
                ->whereHas('informacionLaboral', fn($q) => $q->where('estado', 'Activo'))
                ->whereNotNull('fecha_nacimiento')
                ->whereDate('fecha_nacimiento', '<=', $hoy->copy()->subYears(59))
                ->get()
                ->map(function (Empleado $emp) use ($hoy) {
                    $nac  = $emp->fecha_nacimiento;
                    $a60  = Fechas::aniversarioEn($nac, $nac->year + 60);
                    $a65  = Fechas::aniversarioEn($nac, $nac->year + 65);
                    $edad = (int) $nac->diffInYears($hoy);

                    // Próximo cumpleaños clave: 60 si aún no los cumple, si no 65; con 65 o más ya no hay.
                    $proximo = $a60->gt($hoy) ? $a60 : ($a65->gt($hoy) ? $a65 : null);

                    return [
                        'id'             => $emp->id,
                        'nombre'         => trim("{$emp->nombres} {$emp->apellidos}"),
                        'departamento'   => $emp->departamento?->nombre ?? '—',
                        'cargo'          => $emp->cargo?->nombre ?? '—',
                        'edad'           => $edad,
                        'fecha_60'       => $a60->toDateString(),
                        'fecha_65'       => $a65->toDateString(),
                        'proximo'        => $proximo ? ($proximo->eq($a60) ? 60 : 65) : null,
                        'dias_proximo'   => $proximo ? (int) $hoy->diffInDays($proximo) : null,
                    ];
                })
                ->sortBy(fn($e) => [$e['dias_proximo'] === null ? 1 : 0, $e['dias_proximo'] ?? -$e['edad']])
                ->values()
        );
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
