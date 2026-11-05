<?php

namespace App\Http\Controllers;

use App\Models\DetallePlanilla;
use App\Models\Empleado;
use App\Traits\EncabezadoExcel;
use App\Traits\LogsActividad;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

// Estadística Laboral → Salarios Devengados: para varios o todos los empleados
// elegidos, su salario mensual y lo devengado en las planillas de un rango de fechas.
// Devengado = lo ganado antes de deducciones (salario de los días trabajados + horas
// extras + otros ingresos); también se informa el neto pagado.
// Igual que Estadística Laboral, solo cuenta lo trabajado desde su fecha de inicio actual.
class SalariosDevengadosController extends Controller
{
    use EncabezadoExcel, LogsActividad;

    private function validar(Request $request): array
    {
        return $request->validate([
            'ids'          => 'required|array|min:1',
            'ids.*'        => 'integer',
            'fecha_inicio' => 'required|date',
            'fecha_fin'    => 'required|date|after_or_equal:fecha_inicio',
        ], [
            'ids.required'          => 'Selecciona al menos un empleado.',
            'fecha_inicio.required' => 'Indica la fecha de inicio del rango.',
            'fecha_fin.required'    => 'Indica la fecha de fin del rango.',
            'fecha_fin.after_or_equal' => 'La fecha de fin debe ser igual o posterior a la de inicio.',
        ]);
    }

    // Una fila por empleado elegido; los que no tienen planillas en el rango salen en cero.
    private function filas(array $datos): Collection
    {
        $sumas = DetallePlanilla::query()
            ->join('empleados', 'empleados.id', '=', 'detalle_planillas.id_empleado')
            ->join('informacion_laboral as il', 'il.id', '=', 'empleados.id_info_laboral')
            ->whereIn('detalle_planillas.id_empleado', $datos['ids'])
            ->whereColumn('detalle_planillas.fecha_generada', '>=', 'il.fecha_inicio')
            ->whereBetween('detalle_planillas.fecha_generada', [$datos['fecha_inicio'], $datos['fecha_fin']])
            ->groupBy('detalle_planillas.id_empleado')
            ->get([
                'detalle_planillas.id_empleado',
                DB::raw('COUNT(*) as quincenas'),
                DB::raw('SUM(detalle_planillas.dias_trabajados) as dias'),
                DB::raw('SUM(detalle_planillas.salario_base + detalle_planillas.monto_horas_extras + detalle_planillas.otros_ingresos) as devengado'),
                DB::raw('SUM(detalle_planillas.salario_neto) as neto'),
            ])
            ->keyBy('id_empleado');

        return Empleado::with(['departamento:id,nombre', 'informacionLaboral:id,salario_base,estado'])
            ->whereIn('empleados.id', $datos['ids'])
            ->leftJoin('departamentos', 'departamentos.id', '=', 'empleados.id_departamento')
            ->orderBy('departamentos.nombre')->orderBy('empleados.nombres')->orderBy('empleados.apellidos')
            ->get(['empleados.*'])
            ->map(function (Empleado $emp) use ($sumas) {
                $s = $sumas[$emp->id] ?? null;

                return [
                    'id'              => $emp->id,
                    'nombre'          => trim("{$emp->nombres} {$emp->apellidos}"),
                    'departamento'    => $emp->departamento?->nombre ?? '—',
                    'estado'          => $emp->informacionLaboral?->estado,
                    'salario_mensual' => round((float) ($emp->informacionLaboral?->salario_base ?? 0), 2),
                    'quincenas'       => (int) ($s->quincenas ?? 0),
                    'dias'            => round((float) ($s->dias ?? 0), 2),
                    'devengado'       => round((float) ($s->devengado ?? 0), 2),
                    'neto'            => round((float) ($s->neto ?? 0), 2),
                ];
            })
            ->values();
    }

    private function totales(Collection $filas): array
    {
        return [
            'empleados'       => $filas->count(),
            'salario_mensual' => round($filas->sum('salario_mensual'), 2),
            'devengado'       => round($filas->sum('devengado'), 2),
            'neto'            => round($filas->sum('neto'), 2),
        ];
    }

    public function index(Request $request)
    {
        $datos = $this->validar($request);
        $filas = $this->filas($datos);

        return response()->json(['filas' => $filas, 'totales' => $this->totales($filas)]);
    }

    public function excel(Request $request)
    {
        $datos   = $this->validar($request);
        $filas   = $this->filas($datos);
        $totales = $this->totales($filas);
        $desde   = Carbon::parse($datos['fecha_inicio'])->format('d/m/Y');
        $hasta   = Carbon::parse($datos['fecha_fin'])->format('d/m/Y');

        $columnas  = ['A' => 'Nombre completo', 'B' => 'Departamento', 'C' => 'Salario mensual', 'D' => 'Salario devengado', 'E' => 'Neto pagado'];
        $ultimaCol = 'E';

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Salarios devengados');

        $this->encabezadoExcel($sheet, $ultimaCol, [
            ['INVERSIONES Y SERVICIOS S.A - HOTEL PALMA REAL', 14],
            ['SALARIOS DEVENGADOS', 12],
            [sprintf('Del %s al %s   |   Empleados: %d   |   Generado: %s', $desde, $hasta, $filas->count(), now()->format('d/m/Y')), null],
        ]);

        $row = 5;
        foreach ($columnas as $col => $titulo) {
            $sheet->setCellValue("{$col}{$row}", $titulo);
        }
        $sheet->getStyle("A{$row}:{$ultimaCol}{$row}")->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $sheet->getStyle("A{$row}:{$ultimaCol}{$row}")->getFill()
            ->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('3B2B16');
        $row++;

        foreach ($filas as $f) {
            $sheet->setCellValue("A{$row}", $f['nombre']);
            $sheet->setCellValue("B{$row}", $f['departamento']);
            $sheet->setCellValueExplicit("C{$row}", $f['salario_mensual'], DataType::TYPE_NUMERIC);
            $sheet->setCellValueExplicit("D{$row}", $f['devengado'], DataType::TYPE_NUMERIC);
            $sheet->setCellValueExplicit("E{$row}", $f['neto'], DataType::TYPE_NUMERIC);
            $row++;
        }

        $sheet->setCellValue("A{$row}", 'TOTAL');
        $sheet->setCellValueExplicit("C{$row}", $totales['salario_mensual'], DataType::TYPE_NUMERIC);
        $sheet->setCellValueExplicit("D{$row}", $totales['devengado'], DataType::TYPE_NUMERIC);
        $sheet->setCellValueExplicit("E{$row}", $totales['neto'], DataType::TYPE_NUMERIC);
        $sheet->getStyle("A{$row}:{$ultimaCol}{$row}")->getFont()->setBold(true);
        $sheet->getStyle("A{$row}:{$ultimaCol}{$row}")->getFill()
            ->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F8F2DF');

        $sheet->getStyle("C6:E{$row}")->getNumberFormat()->setFormatCode('#,##0.00');
        foreach (array_keys($columnas) as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $tempFile = tempnam(sys_get_temp_dir(), 'devengados') . '.xlsx';
        $this->anchoColumnaLogo($sheet);
        (new Xlsx($spreadsheet))->save($tempFile);

        $this->logActividad('generado', 'Empleados', "Exportó salarios devengados del {$desde} al {$hasta} de {$filas->count()} empleado(s) a Excel.");

        return response()->download($tempFile, 'SalariosDevengados_' . now()->format('dmY') . '.xlsx')
            ->deleteFileAfterSend(true);
    }
}
