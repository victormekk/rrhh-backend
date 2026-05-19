<?php

namespace App\Http\Controllers;

use App\Models\CabeceraPlanilla;
use App\Models\DeduccionCuota;
use App\Models\DetallePlanilla;
use App\Models\Empleado;
use App\Models\OtroIngreso;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PlanillaController extends Controller
{
    public function index(Request $request)
    {
        $planillas = CabeceraPlanilla::withCount('detalles')
            ->withSum('detalles', 'salario_neto')
            ->when($request->tipo,   fn($q, $t) => $q->where('tipo_planilla', $t))
            ->when($request->estado, fn($q, $e) => $q->where('estado', $e))
            ->orderByDesc('fecha_generada')
            ->paginate(15);

        return response()->json($planillas);
    }

    public function show($id)
    {
        $planilla = CabeceraPlanilla::with([
            'detalles' => fn($q) => $q->with('empleado:id,nombres,apellidos,foto_path')
                                      ->orderBy('departamento')
                                      ->orderBy('id_empleado'),
        ])->findOrFail($id);

        $planilla->totales = $this->calcularTotales($planilla);

        return response()->json($planilla);
    }

    public function store(Request $request)
    {
        $request->validate([
            'nombre_planilla' => 'required|string|max:50',
            'tipo_planilla'   => 'required|in:Fijos,Extras,Especial',
            'fecha_generada'  => 'required|date',
        ]);

        return DB::transaction(function () use ($request) {
            $cabecera = CabeceraPlanilla::create([
                'nombre_planilla' => $request->nombre_planilla,
                'tipo_planilla'   => $request->tipo_planilla,
                'estado'          => 'Activo',
                'fecha_generada'  => $request->fecha_generada,
                'id_usuario'      => $request->user()->id,
            ]);

            $empleados = Empleado::with(['informacionLaboral.banco', 'departamento'])
                ->whereHas('informacionLaboral', fn($q) => $q->where('estado', 'Activo'))
                ->get();

            foreach ($empleados as $emp) {
                $il              = $emp->informacionLaboral;
                $diasTrabajados  = $request->tipo_planilla === 'Fijos' ? 15 : 0;
                $salarioBase     = round($il->salario_diario * $diasTrabajados, 2);

                $otrosIngresos = OtroIngreso::where('id_empleado', $emp->id)
                    ->where('nombre_planilla', $request->nombre_planilla)
                    ->sum('monto');

                $descIngresos = OtroIngreso::where('id_empleado', $emp->id)
                    ->where('nombre_planilla', $request->nombre_planilla)
                    ->pluck('descripcion')->filter()->implode(', ');

                $cuotasMonto = DeduccionCuota::where('id_empleado', $emp->id)
                    ->where('estado', 'Activo')
                    ->sum('monto');

                $ihss  = $this->calcularIhss($salarioBase);
                $rap   = round($salarioBase * 0.015, 2);
                $isr   = $this->calcularIsr($salarioBase);

                $deduccionNeta = $ihss + $rap + $isr + $cuotasMonto;
                $salarioNeto   = $salarioBase + $otrosIngresos - $deduccionNeta;

                DetallePlanilla::create([
                    'id_cabecera_planilla'   => $cabecera->id,
                    'id_empleado'            => $emp->id,
                    'nombre_planilla'        => $request->nombre_planilla,
                    'departamento'           => $emp->departamento?->nombre ?? '',
                    'tipo_planilla'          => $request->tipo_planilla,
                    'dias_trabajados'        => $diasTrabajados,
                    'salario_diario'         => $il->salario_diario,
                    'salario_base'           => $salarioBase,
                    'desc_ingresos'          => $descIngresos ?: null,
                    'otros_ingresos'         => $otrosIngresos,
                    'ihss'                   => $ihss,
                    'retencion_ahorro'       => $rap,
                    'isr'                    => $isr,
                    'crefisa'                => 0,
                    'transporte'             => 0,
                    'radios'                 => 0,
                    'uniforme'               => 0,
                    'garden'                 => 0,
                    'otras_deducciones'      => $cuotasMonto,
                    'desc_otras_deducciones' => null,
                    'deduccion_neta'         => $deduccionNeta,
                    'salario_neto'           => $salarioNeto,
                    'cuenta_banco'           => $il->num_cuenta,
                    'fecha_generada'         => $request->fecha_generada,
                    'id_usuario'             => $request->user()->id,
                ]);
            }

            return response()->json(
                $cabecera->loadCount('detalles')->loadSum('detalles', 'salario_neto'),
                201
            );
        });
    }

    public function updateDetalle(Request $request, $planillaId, $detalleId)
    {
        $detalle = DetallePlanilla::where('id_cabecera_planilla', $planillaId)
            ->findOrFail($detalleId);

        // Verificar que la planilla no está cerrada
        $planilla = CabeceraPlanilla::findOrFail($planillaId);
        abort_if($planilla->estado === 'Cerrado', 422, 'No se puede editar una planilla cerrada.');

        $request->validate([
            'dias_trabajados'        => 'sometimes|integer|min:0|max:30',
            'otros_ingresos'         => 'sometimes|numeric|min:0',
            'desc_ingresos'          => 'nullable|string|max:100',
            'ihss'                   => 'sometimes|numeric|min:0',
            'retencion_ahorro'       => 'sometimes|numeric|min:0',
            'crefisa'                => 'sometimes|numeric|min:0',
            'isr'                    => 'sometimes|numeric|min:0',
            'transporte'             => 'sometimes|numeric|min:0',
            'radios'                 => 'sometimes|numeric|min:0',
            'uniforme'               => 'sometimes|numeric|min:0',
            'garden'                 => 'sometimes|numeric|min:0',
            'otras_deducciones'      => 'sometimes|numeric|min:0',
            'desc_otras_deducciones' => 'nullable|string|max:100',
        ]);

        $dias        = $request->input('dias_trabajados', $detalle->dias_trabajados);
        $salarioBase = round($detalle->salario_diario * $dias, 2);

        $get = fn(string $field) => (float) $request->input($field, $detalle->$field);

        $deduccionNeta = $get('ihss') + $get('retencion_ahorro') + $get('crefisa')
            + $get('isr') + $get('transporte') + $get('radios')
            + $get('uniforme') + $get('garden') + $get('otras_deducciones');

        $salarioNeto = $salarioBase + $get('otros_ingresos') - $deduccionNeta;

        $detalle->update([
            ...$request->only([
                'dias_trabajados', 'otros_ingresos', 'desc_ingresos',
                'ihss', 'retencion_ahorro', 'crefisa', 'isr',
                'transporte', 'radios', 'uniforme', 'garden',
                'otras_deducciones', 'desc_otras_deducciones',
            ]),
            'salario_base'   => $salarioBase,
            'deduccion_neta' => $deduccionNeta,
            'salario_neto'   => $salarioNeto,
        ]);

        return response()->json($detalle->fresh(['empleado:id,nombres,apellidos']));
    }

    public function cerrar(Request $request, $id)
    {
        $planilla = CabeceraPlanilla::where('estado', 'Activo')->findOrFail($id);

        DB::transaction(function () use ($planilla) {
            $planilla->update(['estado' => 'Cerrado']);

            // Aplicar cuotas a cada empleado de esta planilla
            $empleadoIds = DetallePlanilla::where('id_cabecera_planilla', $planilla->id)
                ->pluck('id_empleado');

            DeduccionCuota::whereIn('id_empleado', $empleadoIds)
                ->where('estado', 'Activo')
                ->get()
                ->each(function ($cuota) {
                    $cuota->increment('cuotas_aplicadas');
                    if ($cuota->cuotas_aplicadas >= $cuota->total_cuotas) {
                        $cuota->update(['estado' => 'Completado']);
                    }
                });
        });

        return response()->json(['message' => 'Planilla cerrada y cuotas aplicadas correctamente.']);
    }

    public function destroy($id)
    {
        $planilla = CabeceraPlanilla::where('estado', 'Activo')->findOrFail($id);
        $planilla->detalles()->delete();
        $planilla->delete();

        return response()->json(['message' => 'Planilla eliminada.']);
    }

    public function exportPdf($id)
    {
        $planilla = CabeceraPlanilla::with([
            'detalles' => fn($q) => $q->with('empleado:id,nombres,apellidos')
                                      ->orderBy('departamento'),
        ])->findOrFail($id);

        $totales  = $this->calcularTotales($planilla);
        $pdf      = Pdf::loadView('planillas.pdf', compact('planilla', 'totales'))
            ->setPaper('a4', 'landscape');

        return $pdf->download("planilla_{$planilla->id}_{$planilla->nombre_planilla}.pdf");
    }

    // ─── Helpers ────────────────────────────────────────────────

    private function calcularIhss(float $quincenal): float
    {
        $techo = 25500.00;
        return round(min($quincenal, $techo) * 0.035, 2);
    }

    private function calcularIsr(float $quincenal): float
    {
        $anual = $quincenal * 24;

        $isr = match (true) {
            $anual <= 187016 => 0,
            $anual <= 282437 => ($anual - 187016) * 0.15,
            $anual <= 376583 => (282437 - 187016) * 0.15 + ($anual - 282437) * 0.20,
            default          => (282437 - 187016) * 0.15
                + (376583 - 282437) * 0.20
                + ($anual - 376583) * 0.25,
        };

        return round($isr / 24, 2);
    }

    private function calcularTotales(CabeceraPlanilla $planilla): array
    {
        return $planilla->detalles->reduce(function (array $acc, DetallePlanilla $d) {
            $acc['salario_base']     += $d->salario_base;
            $acc['otros_ingresos']   += $d->otros_ingresos;
            $acc['ihss']             += $d->ihss;
            $acc['retencion_ahorro'] += $d->retencion_ahorro;
            $acc['isr']              += $d->isr;
            $acc['crefisa']          += $d->crefisa;
            $acc['transporte']       += $d->transporte;
            $acc['radios']           += $d->radios;
            $acc['uniforme']         += $d->uniforme;
            $acc['garden']           += $d->garden;
            $acc['otras_deducciones']+= $d->otras_deducciones;
            $acc['deduccion_neta']   += $d->deduccion_neta;
            $acc['salario_neto']     += $d->salario_neto;
            return $acc;
        }, array_fill_keys([
            'salario_base','otros_ingresos','ihss','retencion_ahorro','isr',
            'crefisa','transporte','radios','uniforme','garden',
            'otras_deducciones','deduccion_neta','salario_neto',
        ], 0));
    }
}
