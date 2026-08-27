<?php

namespace App\Http\Controllers;

use App\Models\CabeceraPlanilla;
use App\Models\CampoVariable;
use App\Models\DeduccionCuota;
use App\Models\DetallePlanilla;
use App\Models\Empleado;
use App\Models\OtroIngreso;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

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
            // Leer campos variables al momento de crear la planilla
            $campos   = CampoVariable::whereIn('nombre_campo', ['ihss'])->get()->keyBy('nombre_campo');
            $ihssFijo = (float) ($campos['ihss']->monto ?? 297.58);

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

            // Pre-cargar para evitar N+1: 2 queries en lugar de 2×N
            $ingresosMap = OtroIngreso::where('nombre_planilla', $request->nombre_planilla)
                ->get()->groupBy('id_empleado');

            $cuotasMap = DeduccionCuota::whereIn('id_empleado', $empleados->pluck('id'))
                ->where('estado', 'Activo')
                ->get()->groupBy('id_empleado');

            foreach ($empleados as $emp) {
                $il              = $emp->informacionLaboral;
                $diasTrabajados  = $request->tipo_planilla === 'Fijos' ? 15 : 0;
                $salarioBase     = round($il->salario_diario * $diasTrabajados, 2);

                $empIngresos   = $ingresosMap->get($emp->id, collect());
                $otrosIngresos = (float) $empIngresos->sum('monto');
                $descIngresos  = $empIngresos->pluck('descripcion')->filter()->implode(', ');
                $cuotasMonto   = (float) $cuotasMap->get($emp->id, collect())->sum('monto');

                $ihss  = $ihssFijo; // valor fijo configurable desde Campos Variables

                // RAP e ISR se editan a mano por empleado (no calzan con una formula automatica
                // en la practica real de nomina), arrancan en 0.
                $deduccionNeta = $ihss + $cuotasMonto;
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
                    'horas_extras'           => 0,
                    'monto_horas_extras'     => 0,
                    'ihss'                   => $ihss,
                    'retencion_ahorro'       => 0,
                    'isr'                    => 0,
                    'crefisa'                => 0,
                    'transporte'             => 0,
                    'radios'                 => 0,
                    'uniforme'               => 0,
                    'garden'                 => 0,
                    'i_vecinal'              => 0,
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
            'horas_extras'           => 'sometimes|numeric|min:0|max:200',
            'ihss'                   => 'sometimes|numeric|min:0',
            'retencion_ahorro'       => 'sometimes|numeric|min:0',
            'crefisa'                => 'sometimes|numeric|min:0',
            'isr'                    => 'sometimes|numeric|min:0',
            'transporte'             => 'sometimes|numeric|min:0',
            'radios'                 => 'sometimes|numeric|min:0',
            'uniforme'               => 'sometimes|numeric|min:0',
            'garden'                 => 'sometimes|numeric|min:0',
            'i_vecinal'              => 'sometimes|numeric|min:0',
            'otras_deducciones'      => 'sometimes|numeric|min:0',
            'desc_otras_deducciones' => 'nullable|string|max:100',
        ]);

        $dias        = $request->input('dias_trabajados', $detalle->dias_trabajados);
        $salarioBase = round($detalle->salario_diario * $dias, 2);

        $get = fn(string $field) => (float) $request->input($field, $detalle->$field);

        // El monto de horas extra siempre se recalcula en el servidor a partir del salario
        // diario propio de este empleado — nunca se confia en un monto enviado por el cliente.
        $horasExtras       = $get('horas_extras');
        $montoHorasExtras  = round($detalle->salario_diario / 8 * $horasExtras, 2);

        $deduccionNeta = $get('ihss') + $get('retencion_ahorro') + $get('crefisa')
            + $get('isr') + $get('transporte') + $get('radios')
            + $get('uniforme') + $get('garden') + $get('i_vecinal') + $get('otras_deducciones');

        $salarioNeto = $salarioBase + $get('otros_ingresos') + $montoHorasExtras - $deduccionNeta;

        $detalle->update([
            ...$request->only([
                'dias_trabajados', 'otros_ingresos', 'desc_ingresos',
                'ihss', 'retencion_ahorro', 'crefisa', 'isr',
                'transporte', 'radios', 'uniforme', 'garden', 'i_vecinal',
                'otras_deducciones', 'desc_otras_deducciones',
            ]),
            'horas_extras'       => $horasExtras,
            'monto_horas_extras' => $montoHorasExtras,
            'salario_base'       => $salarioBase,
            'deduccion_neta'     => $deduccionNeta,
            'salario_neto'       => $salarioNeto,
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
            ->setPaper('letter', 'landscape');

        $n = iconv('UTF-8', 'ASCII//TRANSLIT', $planilla->nombre_planilla) ?? $planilla->nombre_planilla;
        $n = preg_replace('/[^a-zA-Z0-9+\-]/', '', str_replace(' ', '', $n));

        return $pdf->download(now()->format('dmY') . '-' . $n . '-planilla.pdf');
    }

    public function exportExcel($id)
    {
        $planilla = CabeceraPlanilla::with([
            'detalles' => fn($q) => $q->with('empleado:id,nombres,apellidos')
                                      ->orderBy('departamento')
                                      ->orderBy('id_empleado'),
        ])->findOrFail($id);

        $spreadsheet = new Spreadsheet();
        $sheet       = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Pago');
        $sheet->setCellValue('A1', 'Empleado');
        $sheet->setCellValue('B1', 'Salario Neto');
        $sheet->getStyle('A1:B1')->getFont()->setBold(true);

        $row = 2;
        foreach ($planilla->detalles as $d) {
            $sheet->setCellValue("A{$row}", trim("{$d->empleado->nombres} {$d->empleado->apellidos}"));
            $sheet->setCellValueExplicit("B{$row}", round((float) $d->salario_neto, 2), DataType::TYPE_NUMERIC);
            $row++;
        }

        $sheet->getStyle("B2:B" . ($row - 1))->getNumberFormat()->setFormatCode('#,##0.00');
        foreach (['A', 'B'] as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $n = iconv('UTF-8', 'ASCII//TRANSLIT', $planilla->nombre_planilla) ?? $planilla->nombre_planilla;
        $n = preg_replace('/[^a-zA-Z0-9+\-]/', '', str_replace(' ', '', $n));

        $tempFile = tempnam(sys_get_temp_dir(), 'planilla') . '.xlsx';
        (new Xlsx($spreadsheet))->save($tempFile);

        return response()->download($tempFile, now()->format('dmY') . '-' . $n . '-pago.xlsx')
            ->deleteFileAfterSend(true);
    }

    // ─── Helpers ────────────────────────────────────────────────

    private function calcularIhss(float $quincenal): float
    {
        $techo = 25500.00;
        return round(min($quincenal, $techo) * 0.035, 2);
    }

    private function calcularTotales(CabeceraPlanilla $planilla): array
    {
        return $planilla->detalles->reduce(function (array $acc, DetallePlanilla $d) {
            $acc['salario_base']       += $d->salario_base;
            $acc['otros_ingresos']     += $d->otros_ingresos;
            $acc['horas_extras']       += $d->horas_extras;
            $acc['monto_horas_extras'] += $d->monto_horas_extras;
            $acc['ihss']               += $d->ihss;
            $acc['retencion_ahorro']   += $d->retencion_ahorro;
            $acc['isr']                += $d->isr;
            $acc['crefisa']            += $d->crefisa;
            $acc['transporte']         += $d->transporte;
            $acc['radios']             += $d->radios;
            $acc['uniforme']           += $d->uniforme;
            $acc['garden']             += $d->garden;
            $acc['i_vecinal']          += $d->i_vecinal;
            $acc['otras_deducciones']  += $d->otras_deducciones;
            $acc['deduccion_neta']     += $d->deduccion_neta;
            $acc['salario_neto']       += $d->salario_neto;
            return $acc;
        }, array_fill_keys([
            'salario_base','otros_ingresos','horas_extras','monto_horas_extras',
            'ihss','retencion_ahorro','isr','crefisa','transporte','radios',
            'uniforme','garden','i_vecinal','otras_deducciones',
            'deduccion_neta','salario_neto',
        ], 0));
    }
}
