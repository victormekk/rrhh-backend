<?php

namespace App\Http\Controllers;

use App\Calculos\Fechas;
use App\Models\Empleado;
use App\Traits\EncabezadoExcel;
use App\Traits\LogsActividad;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

// Reportes → Listado de Cumpleaños: empleados activos de un mes (o de todo el año),
// opcionalmente de ciertos departamentos, en formato de lista para Excel, con la
// edad que cumplen este año (a diferencia del PDF de Cumpleaños, que se publica).
class ListadoCumpleanosController extends Controller
{
    use EncabezadoExcel, LogsActividad;

    private const MESES = [
        1 => 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio',
        'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre',
    ];

    private function validar(Request $request): array
    {
        return $request->validate([
            'mes'             => 'nullable|integer|between:0,12', // 0 o vacío = todos los meses
            'departamentos'   => 'nullable|array',
            'departamentos.*' => 'integer',
        ]);
    }

    // Por mes, día y nombre.
    private function filas(array $datos): Collection
    {
        $mes    = (int) ($datos['mes'] ?? 0);
        $deptos = $datos['departamentos'] ?? [];
        $anio   = Carbon::now()->year;

        return Empleado::with('departamento:id,nombre')
            ->whereNotNull('fecha_nacimiento')
            ->whereHas('informacionLaboral', fn ($q) => $q->where('estado', 'Activo'))
            ->when($mes, fn ($q) => $q->whereRaw('MONTH(fecha_nacimiento) = ?', [$mes]))
            ->when($deptos, fn ($q) => $q->whereIn('id_departamento', $deptos))
            ->orderByRaw('MONTH(fecha_nacimiento), DAY(fecha_nacimiento)')
            ->orderBy('nombres')
            ->orderBy('apellidos')
            ->get()
            ->map(function ($emp) use ($anio) {
                // Quien nació un 29 de febrero celebra el 28 en años no bisiestos.
                $fecha = Fechas::aniversarioEn($emp->fecha_nacimiento, $anio);

                return [
                    'id'           => $emp->id,
                    'nombre'       => trim("{$emp->nombres} {$emp->apellidos}"),
                    'departamento' => $emp->departamento?->nombre ?? '—',
                    'telefono'     => $emp->telefono ?: '—',
                    'mes'          => $fecha->month,
                    'dia'          => $fecha->day,
                    'cumpleanos'   => $fecha->day . ' de ' . self::MESES[$fecha->month],
                    'edad'         => $anio - $emp->fecha_nacimiento->year,
                ];
            });
    }

    public function index(Request $request)
    {
        return response()->json($this->filas($this->validar($request)));
    }

    public function excel(Request $request)
    {
        $datos  = $this->validar($request);
        $filas  = $this->filas($datos);
        $mes    = (int) ($datos['mes'] ?? 0);
        $titulo = $mes ? 'LISTADO DE CUMPLEAÑOS - ' . mb_strtoupper(self::MESES[$mes]) : 'LISTADO DE CUMPLEAÑOS - TODO EL AÑO';

        $columnas  = ['A' => 'Nombre completo', 'B' => 'Departamento', 'C' => 'Cumpleaños', 'D' => 'Edad que cumple', 'E' => 'Teléfono'];
        $ultimaCol = 'E';

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Cumpleaños');

        $this->encabezadoExcel($sheet, $ultimaCol, [
            ['INVERSIONES Y SERVICIOS S.A - HOTEL PALMA REAL', 14],
            [$titulo, 12],
            [sprintf('Generado: %s   |   Empleados: %d', now()->format('d/m/Y'), $filas->count()), null],
        ]);

        $row = 5;
        foreach ($columnas as $col => $texto) {
            $sheet->setCellValue("{$col}{$row}", $texto);
        }
        $sheet->getStyle("A{$row}:{$ultimaCol}{$row}")->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $sheet->getStyle("A{$row}:{$ultimaCol}{$row}")->getFill()
            ->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('3B2B16');
        $row++;

        foreach ($filas as $f) {
            $sheet->setCellValue("A{$row}", $f['nombre']);
            $sheet->setCellValue("B{$row}", $f['departamento']);
            // Como texto, para que Excel no lo tome como número ni le quite el guion.
            $sheet->setCellValue("C{$row}", $f['cumpleanos']);
            $sheet->setCellValue("D{$row}", "{$f['edad']} años");
            $sheet->setCellValueExplicit("E{$row}", $f['telefono'], DataType::TYPE_STRING);
            $row++;
        }

        if ($filas->isEmpty()) {
            $sheet->setCellValue("A{$row}", 'Ningún empleado activo cumple años en lo seleccionado.');
            $sheet->mergeCells("A{$row}:{$ultimaCol}{$row}");
            $row++;
        }

        $sheet->getStyle("A5:{$ultimaCol}" . ($row - 1))->getBorders()->getAllBorders()
            ->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('D6D3D1');
        $sheet->getStyle('C6:E' . ($row - 1))->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        foreach (array_keys($columnas) as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $tempFile = tempnam(sys_get_temp_dir(), 'cumpleanos') . '.xlsx';
        $this->anchoColumnaLogo($sheet);
        (new Xlsx($spreadsheet))->save($tempFile);

        $periodo = $mes ? self::MESES[$mes] : 'todo el año';
        $this->logActividad('generado', 'Empleados', "Exportó el listado de cumpleaños de {$periodo} a Excel ({$filas->count()} empleado(s)).");

        $nombre = $mes ? self::MESES[$mes] : 'Anual';

        return response()->download($tempFile, "ListadoCumpleanos_{$nombre}_" . now()->format('dmY') . '.xlsx')
            ->deleteFileAfterSend(true);
    }
}
