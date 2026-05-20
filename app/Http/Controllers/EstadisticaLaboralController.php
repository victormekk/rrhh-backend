<?php

namespace App\Http\Controllers;

use App\Models\DetallePlanilla;
use App\Models\Empleado;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class EstadisticaLaboralController extends Controller
{
    // ── helpers ──────────────────────────────────────────────────────────────

    private function baseQuery(Request $request)
    {
        return DetallePlanilla::query()
            ->join('empleados',     'detalle_planillas.id_empleado',   '=', 'empleados.id')
            ->leftJoin('departamentos', 'empleados.id_departamento', '=', 'departamentos.id')
            ->when($request->fecha_inicio, fn($q) =>
                $q->where('detalle_planillas.fecha_generada', '>=', $request->fecha_inicio))
            ->when($request->fecha_fin, fn($q) =>
                $q->where('detalle_planillas.fecha_generada', '<=', $request->fecha_fin))
            ->when($request->search, function ($q) use ($request) {
                $s = $request->search;
                $q->where(function ($sub) use ($s) {
                    $sub->where('empleados.nombres',   'like', "%{$s}%")
                        ->orWhere('empleados.apellidos', 'like', "%{$s}%");
                });
            });
    }

    private function totalesFrom(Request $request): object
    {
        return $this->baseQuery($request)->select([
            DB::raw('SUM(detalle_planillas.dias_trabajados)  as total_dias'),
            DB::raw('SUM(detalle_planillas.salario_neto)     as total_salario_neto'),
            DB::raw('SUM(detalle_planillas.salario_base)     as total_salario_base'),
            DB::raw('SUM(detalle_planillas.otros_ingresos)   as total_otros_ingresos'),
            DB::raw('SUM(detalle_planillas.deduccion_neta)   as total_deducciones'),
            DB::raw('COUNT(DISTINCT detalle_planillas.id_empleado) as total_empleados'),
            DB::raw('COUNT(*)                                as total_quincenas'),
        ])->first();
    }

    private function rowsQuery(Request $request)
    {
        return $this->baseQuery($request)->select([
            'detalle_planillas.id_empleado',
            DB::raw('empleados.nombres   as nombres'),
            DB::raw('empleados.apellidos as apellidos'),
            DB::raw('COALESCE(departamentos.nombre, "—") as departamento'),
            DB::raw('SUM(detalle_planillas.dias_trabajados)  as total_dias'),
            DB::raw('SUM(detalle_planillas.salario_neto)     as total_salario_neto'),
            DB::raw('SUM(detalle_planillas.salario_base)     as total_salario_base'),
            DB::raw('SUM(detalle_planillas.otros_ingresos)   as total_otros_ingresos'),
            DB::raw('SUM(detalle_planillas.deduccion_neta)   as total_deducciones'),
            DB::raw('COUNT(*)                                as total_quincenas'),
        ])->groupBy(
            'detalle_planillas.id_empleado',
            DB::raw('empleados.nombres'),
            DB::raw('empleados.apellidos'),
            DB::raw('departamentos.nombre')
        )->orderBy('empleados.apellidos')->orderBy('empleados.nombres');
    }

    // ── endpoints ─────────────────────────────────────────────────────────────

    public function index(Request $request)
    {
        $rows    = $this->rowsQuery($request)->paginate(25);
        $totales = $this->totalesFrom($request);

        return response()->json([
            ...$rows->toArray(),
            'totales' => $totales,
        ]);
    }

    public function show(Request $request, $empleadoId)
    {
        $empleado = Empleado::with(['departamento:id,nombre', 'puesto:id,nombre'])
            ->findOrFail($empleadoId);

        $detalles = DetallePlanilla::where('id_empleado', $empleadoId)
            ->when($request->fecha_inicio, fn($q) => $q->where('fecha_generada', '>=', $request->fecha_inicio))
            ->when($request->fecha_fin,    fn($q) => $q->where('fecha_generada', '<=', $request->fecha_fin))
            ->orderBy('fecha_generada')
            ->get([
                'fecha_generada', 'nombre_planilla', 'tipo_planilla',
                'dias_trabajados', 'salario_diario', 'salario_base',
                'desc_ingresos', 'otros_ingresos',
                'ihss', 'retencion_ahorro', 'isr', 'crefisa',
                'transporte', 'radios', 'uniforme', 'garden',
                'otras_deducciones', 'desc_otras_deducciones',
                'deduccion_neta', 'salario_neto',
            ]);

        return response()->json([
            'empleado' => [
                'id'          => $empleado->id,
                'nombres'     => $empleado->nombres,
                'apellidos'   => $empleado->apellidos,
                'departamento'=> $empleado->departamento?->nombre ?? '—',
                'puesto'      => $empleado->puesto?->nombre ?? '—',
            ],
            'detalles' => $detalles,
            'totales'  => [
                'total_quincenas'    => $detalles->count(),
                'total_dias'         => $detalles->sum('dias_trabajados'),
                'total_salario_base' => $detalles->sum('salario_base'),
                'total_otros_ingresos'=> $detalles->sum('otros_ingresos'),
                'total_deducciones'  => $detalles->sum('deduccion_neta'),
                'total_salario_neto' => $detalles->sum('salario_neto'),
            ],
        ]);
    }

    public function exportPdf(Request $request)
    {
        $rows    = $this->rowsQuery($request)->getQuery()->get();
        $totales = $this->totalesFrom($request);
        $periodo = [
            'inicio' => $request->fecha_inicio,
            'fin'    => $request->fecha_fin,
            'search' => $request->search,
        ];

        $pdf = Pdf::loadView('estadistica.pdf', compact('rows', 'totales', 'periodo'))
            ->setPaper('letter', 'landscape');

        $label = $request->search ?: 'TodosEmpleados';
        $n = iconv('UTF-8', 'ASCII//TRANSLIT', $label) ?? $label;
        $n = preg_replace('/[^a-zA-Z0-9+\-]/', '', str_replace(' ', '', $n));

        return $pdf->download(now()->format('dmY') . '-' . $n . '-estadisticalaboral.pdf');
    }
}
