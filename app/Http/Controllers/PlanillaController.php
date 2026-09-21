<?php

namespace App\Http\Controllers;

use App\Models\CabeceraPlanilla;
use App\Models\CampoVariable;
use App\Models\DeduccionCuota;
use App\Models\DetallePlanilla;
use App\Models\Empleado;
use App\Models\OtroIngreso;
use App\Traits\GeneraCorrelativo;
use App\Traits\LogsActividad;
use App\Traits\NombraArchivos;
use App\Traits\SoloAdmin;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class PlanillaController extends Controller
{
    use LogsActividad, NombraArchivos, SoloAdmin, GeneraCorrelativo;

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
            'detalles' => fn($q) => $this->ordenarPorDeptoYNombre(
                $q->with('empleado:id,nombres,apellidos,foto_path')
            ),
        ])->findOrFail($id);

        $planilla->totales = $this->calcularTotales($planilla->detalles);

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

            // tipo_planilla (Fijos/Extras/Especial) -> tipo_contrato (Fijo/Extra/Especial)
            $tipoContrato = [
                'Fijos'    => 'Fijo',
                'Extras'   => 'Extra',
                'Especial' => 'Especial',
            ][$request->tipo_planilla];

            $empleados = Empleado::with(['informacionLaboral.banco', 'departamento'])
                ->whereHas('informacionLaboral', fn ($q) => $q->where('estado', 'Activo')
                    ->where('tipo_contrato', $tipoContrato))
                ->get();

            // Pre-cargar para evitar N+1: 2 queries en lugar de 2×N
            $ingresosMap = OtroIngreso::where('nombre_planilla', $request->nombre_planilla)
                ->get()->groupBy('id_empleado');

            $cuotasMap = DeduccionCuota::whereIn('id_empleado', $empleados->pluck('id'))
                ->where('estado', 'Activo')
                ->get()->groupBy('id_empleado');

            foreach ($empleados as $emp) {
                $il              = $emp->informacionLaboral;
                $diasTrabajados  = 0;
                $salarioBase     = round($il->salario_diario * $diasTrabajados, 2);

                $empIngresos   = $ingresosMap->get($emp->id, collect());
                $otrosIngresos = (float) $empIngresos->sum('monto');
                $descIngresos  = $empIngresos->pluck('descripcion')->filter()->implode(', ');
                $cuotasMonto   = (float) $cuotasMap->get($emp->id, collect())->sum('monto');

                // El personal de contrato Extra no cotiza IHSS en esta planilla.
                $ihss = $request->tipo_planilla === 'Extras' ? 0 : $ihssFijo;

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

    public function destroy(Request $request, $id)
    {
        $this->soloAdmin($request);

        $planilla = CabeceraPlanilla::where('estado', 'Activo')->findOrFail($id);
        $planilla->detalles()->delete();
        $planilla->delete();

        return response()->json(['message' => 'Planilla eliminada.']);
    }

    // Borrado de una planilla ya CERRADA: solo administradores, y solo
    // confirmando con la contraseña de la sesion (accion mas sensible que
    // anular una planilla activa, porque una cerrada ya aplico cuotas).
    public function eliminarCerrada(Request $request, $id)
    {
        $this->soloAdmin($request);

        $request->validate([
            'password' => 'required|string',
        ]);

        abort_unless(
            Hash::check($request->password, $request->user()->password),
            422,
            'Contraseña incorrecta.'
        );

        $planilla = CabeceraPlanilla::where('estado', 'Cerrado')->findOrFail($id);
        $nombre   = $planilla->nombre_planilla;

        $planilla->detalles()->delete();
        $planilla->delete();

        $this->logActividad(
            'eliminado',
            'Planillas',
            "Planilla cerrada '{$nombre}' eliminada permanentemente.",
            $id
        );

        return response()->json(['message' => 'Planilla eliminada.']);
    }

    public function exportPdf($id)
    {
        $planilla = CabeceraPlanilla::with([
            'detalles' => fn($q) => $this->ordenarPorDeptoYNombre(
                $q->with('empleado:id,nombres,apellidos')
            ),
        ])->findOrFail($id);

        $totales     = $this->calcularTotales($planilla->detalles);
        $correlativo = $this->siguienteCorrelativo('planilla', $planilla->id);
        $pdf         = Pdf::loadView('planillas.pdf', compact('planilla', 'totales', 'correlativo'))
            ->setPaper('letter', 'landscape');

        return $pdf->download($this->sanitizarNombreArchivo($planilla->nombre_planilla) . '.pdf')
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->header('Pragma', 'no-cache');
    }

    public function exportBancosExcel($id)
    {
        return $this->exportPagoExcel($id, 'banco', 'Bancos', 'PAGO POR TRANSFERENCIA BANCARIA');
    }

    public function exportBancosPdf($id)
    {
        return $this->exportPagoPdf($id, 'banco', 'Bancos', 'PAGO POR TRANSFERENCIA BANCARIA', 'planilla_bancos');
    }

    public function exportChequesExcel($id)
    {
        return $this->exportPagoExcel($id, 'cheque', 'Cheques', 'PAGO POR CHEQUE');
    }

    public function exportChequesPdf($id)
    {
        return $this->exportPagoPdf($id, 'cheque', 'Cheques', 'PAGO POR CHEQUE', 'planilla_cheques');
    }

    // Detalles de una planilla que cobran por transferencia (tienen cuenta_banco
    // guardada, capturada de la ficha del empleado al generar la planilla) o por
    // cheque (sin cuenta_banco). Es la unica fuente de verdad para la division:
    // no depende de "forma_de_pago", asi el admin controla el destino de cada
    // quien con solo llenar o vaciar el numero de cuenta en la ficha del empleado.
    private function detallesPorMetodoPago(CabeceraPlanilla $planilla, string $metodo)
    {
        return $planilla->detalles->filter(
            fn($d) => $metodo === 'banco' ? filled($d->cuenta_banco) : blank($d->cuenta_banco)
        )->values();
    }

    private function exportPagoExcel($id, string $metodo, string $sufijoArchivo, string $titulo)
    {
        $planilla = CabeceraPlanilla::with([
            'detalles' => fn($q) => $this->ordenarPorDeptoYNombre(
                $q->with('empleado:id,nombres,apellidos')
            ),
        ])->findOrFail($id);

        $detalles = $this->detallesPorMetodoPago($planilla, $metodo);
        $totales  = $this->calcularTotales($detalles);

        $columnas  = [
            'A' => 'Empleado', 'B' => 'H. Extra', 'C' => 'Otros Ing.',
            'D' => 'IHSS',     'E' => 'Otras Ded.', 'F' => 'Ded. Neta', 'G' => 'Sal. Neto',
        ];
        $ultimaCol = 'G';
        $camposNum = ['monto_horas_extras', 'otros_ingresos', 'ihss', 'otras_deducciones', 'deduccion_neta', 'salario_neto'];

        $spreadsheet = new Spreadsheet();
        $sheet       = $spreadsheet->getActiveSheet();
        $sheet->setTitle($sufijoArchivo);

        $sheet->mergeCells("A1:{$ultimaCol}1");
        $sheet->setCellValue('A1', 'INVERSIONES Y SERVICIOS S.A - HOTEL PALMA REAL');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->getStyle('A1')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);

        $sheet->mergeCells("A2:{$ultimaCol}2");
        $sheet->setCellValue('A2', strtoupper($planilla->nombre_planilla) . ' — ' . $titulo);
        $sheet->getStyle('A2')->getFont()->setBold(true)->setSize(12);
        $sheet->getStyle('A2')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);

        $sheet->mergeCells("A3:{$ultimaCol}3");
        $sheet->setCellValue('A3', sprintf(
            'Tipo: %s   |   Fecha: %s   |   Empleados: %d',
            $planilla->tipo_planilla,
            \Carbon\Carbon::parse($planilla->fecha_generada)->format('d/m/Y'),
            $detalles->count()
        ));
        $sheet->getStyle('A3')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);

        $row = 5;
        foreach ($columnas as $col => $tituloCol) {
            $sheet->setCellValue("{$col}{$row}", $tituloCol);
        }
        $sheet->getStyle("A{$row}:{$ultimaCol}{$row}")->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $sheet->getStyle("A{$row}:{$ultimaCol}{$row}")->getFill()
            ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB('3B2B16');
        $row++;

        foreach ($detalles->groupBy('departamento') as $departamento => $filas) {
            $sheet->setCellValue("A{$row}", $departamento);
            $sheet->mergeCells("A{$row}:{$ultimaCol}{$row}");
            $sheet->getStyle("A{$row}")->getFont()->setBold(true)->getColor()->setRGB('3B2B16');
            $sheet->getStyle("A{$row}")->getFill()
                ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB('F8F2DF');
            $row++;

            foreach ($filas as $d) {
                $sheet->setCellValue("A{$row}", trim("{$d->empleado->nombres} {$d->empleado->apellidos}"));
                $col = 'B';
                foreach ($camposNum as $campo) {
                    $sheet->setCellValueExplicit("{$col}{$row}", round((float) $d->$campo, 2), DataType::TYPE_NUMERIC);
                    $col++;
                }
                $row++;
            }

            $sheet->setCellValue("A{$row}", "SUBTOTAL: {$departamento}");
            $col = 'B';
            foreach ($camposNum as $campo) {
                $sheet->setCellValueExplicit("{$col}{$row}", round((float) $filas->sum($campo), 2), DataType::TYPE_NUMERIC);
                $col++;
            }
            $sheet->getStyle("A{$row}:{$ultimaCol}{$row}")->getFont()->setBold(true);
            $sheet->getStyle("A{$row}:{$ultimaCol}{$row}")->getFill()
                ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB('EEE3C3');
            $row++;
        }

        $sheet->setCellValue("A{$row}", 'TOTAL GENERAL');
        $col = 'B';
        foreach ($camposNum as $campo) {
            $sheet->setCellValueExplicit("{$col}{$row}", round((float) $totales[$campo], 2), DataType::TYPE_NUMERIC);
            $col++;
        }
        $sheet->getStyle("A{$row}:{$ultimaCol}{$row}")->getFont()->setBold(true);
        $sheet->getStyle("A{$row}:{$ultimaCol}{$row}")->getFill()
            ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB('B9921A');

        $sheet->getStyle("B6:{$ultimaCol}{$row}")->getNumberFormat()->setFormatCode('#,##0.00');
        foreach (array_keys($columnas) as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $tempFile = tempnam(sys_get_temp_dir(), 'planilla') . '.xlsx';
        (new Xlsx($spreadsheet))->save($tempFile);

        $nombre = $this->sanitizarNombreArchivo($planilla->nombre_planilla);

        return response()->download($tempFile, "{$sufijoArchivo} ({$nombre}).xlsx")
            ->deleteFileAfterSend(true);
    }

    private function exportPagoPdf($id, string $metodo, string $sufijoArchivo, string $titulo, string $tipoCorrelativo)
    {
        $planilla = CabeceraPlanilla::with([
            'detalles' => fn($q) => $this->ordenarPorDeptoYNombre(
                $q->with('empleado:id,nombres,apellidos')
            ),
        ])->findOrFail($id);

        $detalles    = $this->detallesPorMetodoPago($planilla, $metodo);
        $totales     = $this->calcularTotales($detalles);
        $correlativo = $this->siguienteCorrelativo($tipoCorrelativo, $planilla->id);

        $pdf = Pdf::loadView('planillas.pago-pdf', compact('planilla', 'detalles', 'totales', 'titulo', 'correlativo'))
            ->setPaper('letter', 'portrait');

        $nombre = $this->sanitizarNombreArchivo($planilla->nombre_planilla);

        return $pdf->download("{$sufijoArchivo} ({$nombre}).pdf")
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->header('Pragma', 'no-cache');
    }

    public function exportExcel($id)
    {
        $planilla = CabeceraPlanilla::with([
            'detalles' => fn($q) => $this->ordenarPorDeptoYNombre(
                $q->with('empleado:id,nombres,apellidos')
            ),
        ])->findOrFail($id);

        $totales = $this->calcularTotales($planilla->detalles);

        $columnas = [
            'A' => 'Empleado',     'B' => 'Días',        'C' => 'Sal. Base',
            'D' => 'H. Extra',     'E' => 'Otros Ing.',  'F' => 'IHSS',
            'G' => 'RAP',          'H' => 'ISR',         'I' => 'Crefisa',
            'J' => 'Transp.',      'K' => 'Radios',      'L' => 'I. Vecinal',
            'M' => 'Uniforme',     'N' => 'Garden',      'O' => 'Otras Ded.',
            'P' => 'Ded. Neta',    'Q' => 'Sal. Neto',
        ];
        $ultimaCol  = 'Q';
        $camposNum  = ['salario_base', 'monto_horas_extras', 'otros_ingresos', 'ihss', 'retencion_ahorro',
            'isr', 'crefisa', 'transporte', 'radios', 'i_vecinal', 'uniforme', 'garden',
            'otras_deducciones', 'deduccion_neta', 'salario_neto'];

        $spreadsheet = new Spreadsheet();
        $sheet       = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Planilla');

        $sheet->mergeCells("A1:{$ultimaCol}1");
        $sheet->setCellValue('A1', 'INVERSIONES Y SERVICIOS S.A - HOTEL PALMA REAL');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->getStyle('A1')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);

        $sheet->mergeCells("A2:{$ultimaCol}2");
        $sheet->setCellValue('A2', strtoupper($planilla->nombre_planilla));
        $sheet->getStyle('A2')->getFont()->setBold(true)->setSize(12);
        $sheet->getStyle('A2')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);

        $sheet->mergeCells("A3:{$ultimaCol}3");
        $sheet->setCellValue('A3', sprintf(
            'Tipo: %s   |   Fecha: %s   |   Estado: %s   |   Empleados: %d',
            $planilla->tipo_planilla,
            \Carbon\Carbon::parse($planilla->fecha_generada)->format('d/m/Y'),
            $planilla->estado,
            $planilla->detalles->count()
        ));
        $sheet->getStyle('A3')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);

        $row = 5;
        foreach ($columnas as $col => $titulo) {
            $sheet->setCellValue("{$col}{$row}", $titulo);
        }
        $sheet->getStyle("A{$row}:{$ultimaCol}{$row}")->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $sheet->getStyle("A{$row}:{$ultimaCol}{$row}")->getFill()
            ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB('3B2B16');
        $row++;

        foreach ($planilla->detalles->groupBy('departamento') as $departamento => $filas) {
            $sheet->setCellValue("A{$row}", $departamento);
            $sheet->mergeCells("A{$row}:{$ultimaCol}{$row}");
            $sheet->getStyle("A{$row}")->getFont()->setBold(true)->getColor()->setRGB('3B2B16');
            $sheet->getStyle("A{$row}")->getFill()
                ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB('F8F2DF');
            $row++;

            foreach ($filas as $d) {
                $sheet->setCellValue("A{$row}", trim("{$d->empleado->nombres} {$d->empleado->apellidos}"));
                $sheet->setCellValueExplicit("B{$row}", (int) $d->dias_trabajados, DataType::TYPE_NUMERIC);
                $col = 'C';
                foreach ($camposNum as $campo) {
                    $sheet->setCellValueExplicit("{$col}{$row}", round((float) $d->$campo, 2), DataType::TYPE_NUMERIC);
                    $col++;
                }
                $row++;
            }

            $sheet->setCellValue("A{$row}", "SUBTOTAL: {$departamento}");
            $sheet->setCellValueExplicit("B{$row}", (int) $filas->sum('dias_trabajados'), DataType::TYPE_NUMERIC);
            $col = 'C';
            foreach ($camposNum as $campo) {
                $sheet->setCellValueExplicit("{$col}{$row}", round((float) $filas->sum($campo), 2), DataType::TYPE_NUMERIC);
                $col++;
            }
            $sheet->getStyle("A{$row}:{$ultimaCol}{$row}")->getFont()->setBold(true);
            $sheet->getStyle("A{$row}:{$ultimaCol}{$row}")->getFill()
                ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB('EEE3C3');
            $row++;
        }

        $sheet->setCellValue("A{$row}", 'TOTAL GENERAL');
        $sheet->setCellValueExplicit("B{$row}", (int) $totales['dias_trabajados'], DataType::TYPE_NUMERIC);
        $col = 'C';
        foreach ($camposNum as $campo) {
            $sheet->setCellValueExplicit("{$col}{$row}", round((float) $totales[$campo], 2), DataType::TYPE_NUMERIC);
            $col++;
        }
        $sheet->getStyle("A{$row}:{$ultimaCol}{$row}")->getFont()->setBold(true);
        $sheet->getStyle("A{$row}:{$ultimaCol}{$row}")->getFill()
            ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB('B9921A');

        $sheet->getStyle("C6:{$ultimaCol}{$row}")->getNumberFormat()->setFormatCode('#,##0.00');
        foreach (array_keys($columnas) as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $tempFile = tempnam(sys_get_temp_dir(), 'planilla') . '.xlsx';
        (new Xlsx($spreadsheet))->save($tempFile);

        return response()->download($tempFile, $this->sanitizarNombreArchivo($planilla->nombre_planilla) . '.xlsx')
            ->deleteFileAfterSend(true);
    }

    // Excel simple para el archivo de pago: solo Empleado y Salario Neto, en
    // orden alfabetico por nombre (no agrupado por departamento), unicamente
    // los empleados que cobran por transferencia bancaria (tienen cuenta
    // registrada) — los de cheque no van en este archivo.
    public function exportPagoGeneralExcel($id)
    {
        $planilla = CabeceraPlanilla::with([
            'detalles.empleado:id,nombres,apellidos',
        ])->findOrFail($id);

        $detalles = $this->detallesPorMetodoPago($planilla, 'banco')
            ->sortBy(fn($d) => trim("{$d->empleado->nombres} {$d->empleado->apellidos}"), SORT_NATURAL | SORT_FLAG_CASE)
            ->values();

        $spreadsheet = new Spreadsheet();
        $sheet       = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Pago');

        $sheet->mergeCells('A1:B1');
        $sheet->setCellValue('A1', 'INVERSIONES Y SERVICIOS S.A - HOTEL PALMA REAL');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->getStyle('A1')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);

        $sheet->mergeCells('A2:B2');
        $sheet->setCellValue('A2', strtoupper($planilla->nombre_planilla) . ' — GENERAR PAGO');
        $sheet->getStyle('A2')->getFont()->setBold(true)->setSize(12);
        $sheet->getStyle('A2')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);

        $sheet->mergeCells('A3:B3');
        $sheet->setCellValue('A3', sprintf(
            'Tipo: %s   |   Fecha: %s   |   Empleados: %d',
            $planilla->tipo_planilla,
            \Carbon\Carbon::parse($planilla->fecha_generada)->format('d/m/Y'),
            $detalles->count()
        ));
        $sheet->getStyle('A3')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);

        $row = 5;
        $sheet->setCellValue("A{$row}", 'Empleado');
        $sheet->setCellValue("B{$row}", 'Sal. Neto');
        $sheet->getStyle("A{$row}:B{$row}")->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $sheet->getStyle("A{$row}:B{$row}")->getFill()
            ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB('3B2B16');
        $row++;

        foreach ($detalles as $d) {
            $sheet->setCellValue("A{$row}", trim("{$d->empleado->nombres} {$d->empleado->apellidos}"));
            $sheet->setCellValueExplicit("B{$row}", round((float) $d->salario_neto, 2), DataType::TYPE_NUMERIC);
            $row++;
        }

        $sheet->setCellValue("A{$row}", 'TOTAL GENERAL');
        $sheet->setCellValueExplicit("B{$row}", round((float) $detalles->sum('salario_neto'), 2), DataType::TYPE_NUMERIC);
        $sheet->getStyle("A{$row}:B{$row}")->getFont()->setBold(true);
        $sheet->getStyle("A{$row}:B{$row}")->getFill()
            ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB('B9921A');

        $sheet->getStyle("B6:B{$row}")->getNumberFormat()->setFormatCode('#,##0.00');
        $sheet->getColumnDimension('A')->setWidth(38);
        $sheet->getColumnDimension('B')->setWidth(16);

        $tempFile = tempnam(sys_get_temp_dir(), 'pago') . '.xlsx';
        (new Xlsx($spreadsheet))->save($tempFile);

        $nombre = $this->sanitizarNombreArchivo($planilla->nombre_planilla);

        return response()->download($tempFile, "Pago ({$nombre}).xlsx")
            ->deleteFileAfterSend(true);
    }

    // ─── Helpers ────────────────────────────────────────────────

    // Ordena los detalles de una planilla por departamento (alfabetico) y,
    // dentro de cada departamento, por nombre del empleado (coincide con el
    // orden "Nombres Apellidos" en que se muestra en pantalla/PDF/Excel).
    private function ordenarPorDeptoYNombre($query)
    {
        return $query->join('empleados', 'empleados.id', '=', 'detalle_planillas.id_empleado')
            ->select('detalle_planillas.*')
            ->orderBy('detalle_planillas.departamento')
            ->orderBy('empleados.nombres')
            ->orderBy('empleados.apellidos');
    }

    private function calcularIhss(float $quincenal): float
    {
        $techo = 25500.00;
        return round(min($quincenal, $techo) * 0.035, 2);
    }

    private function calcularTotales($detalles): array
    {
        return $detalles->reduce(function (array $acc, DetallePlanilla $d) {
            $acc['dias_trabajados']    += $d->dias_trabajados;
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
            'dias_trabajados','salario_base','otros_ingresos','horas_extras','monto_horas_extras',
            'ihss','retencion_ahorro','isr','crefisa','transporte','radios',
            'uniforme','garden','i_vecinal','otras_deducciones',
            'deduccion_neta','salario_neto',
        ], 0));
    }
}
