<?php

namespace App\Http\Controllers;

use App\Models\Empleado;
use App\Models\MarcaJustificada;
use App\Models\Marcacion;
use App\Traits\EncabezadoExcel;
use App\Traits\GeneraCorrelativo;
use App\Traits\LogsActividad;
use App\Traits\NombraArchivos;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

// Marcas incompletas: días en que un empleado tiene una sola marca en el reloj
// (solo entrada o solo salida). RRHH las revisa con el jefe y, si corresponde,
// las justifica para que el día cuente como trabajado en la planilla.
class MarcasIncompletasController extends Controller
{
    use LogsActividad, EncabezadoExcel, GeneraCorrelativo, NombraArchivos;

    private const DIAS = ['Domingo', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado'];

    public function index(Request $request)
    {
        [$desde, $hasta] = $this->periodo($request);
        $filas = $this->incompletas($desde, $hasta);

        return response()->json([
            'filas'        => $filas,
            'total'        => $filas->count(),
            'justificadas' => $filas->whereNotNull('justificacion')->count(),
        ]);
    }

    public function justificar(Request $request)
    {
        $data = $request->validate([
            'id_empleado' => 'required|exists:empleados,id',
            'fecha'       => 'required|date',
            'motivo'      => 'required|string|max:255',
        ], [
            'motivo.required' => 'Indique el motivo de la justificación.',
        ]);

        $j = MarcaJustificada::updateOrCreate(
            ['id_empleado' => $data['id_empleado'], 'fecha' => $data['fecha']],
            ['motivo' => trim($data['motivo']), 'id_usuario' => $request->user()->id]
        );

        $emp = Empleado::find($data['id_empleado']);
        $this->logActividad(
            'editado',
            'Planillas',
            "Marca incompleta justificada: {$emp->nombres} {$emp->apellidos}, " . Carbon::parse($data['fecha'])->format('d/m/Y') . " ({$j->motivo}).",
            $emp->id
        );

        return response()->json($this->justificacionJson($j->load('usuario:id,name')));
    }

    public function quitarJustificacion(MarcaJustificada $justificada)
    {
        $emp = $justificada->empleado;
        $fecha = $justificada->fecha->format('d/m/Y');
        $justificada->delete();

        $this->logActividad(
            'eliminado',
            'Planillas',
            "Se quitó la justificación de marca incompleta: {$emp?->nombres} {$emp?->apellidos}, {$fecha}.",
            $emp?->id
        );

        return response()->json(['ok' => true]);
    }

    public function excel(Request $request)
    {
        [$desde, $hasta] = $this->periodo($request);
        $filas = $this->incompletas($desde, $hasta);

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Marcas incompletas');
        $ultimaCol = 'H';

        $this->encabezadoExcel($sheet, $ultimaCol, [
            ['INVERSIONES Y SERVICIOS S.A - HOTEL PALMA REAL', 14],
            ['MARCAS INCOMPLETAS', 12],
            [sprintf('Período: %s al %s   |   Casos: %d   |   Generado: %s',
                Carbon::parse($desde)->format('d/m/Y'), Carbon::parse($hasta)->format('d/m/Y'), $filas->count(), now()->format('d/m/Y')), null],
        ]);

        $row = 5;
        foreach (['A' => 'Empleado', 'B' => 'Departamento', 'C' => 'Cargo', 'D' => 'Fecha', 'E' => 'Día',
                  'F' => 'Marca registrada', 'G' => 'Tipo', 'H' => 'Justificación'] as $col => $titulo) {
            $sheet->setCellValue("{$col}{$row}", $titulo);
        }
        $sheet->getStyle("A{$row}:{$ultimaCol}{$row}")->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $sheet->getStyle("A{$row}:{$ultimaCol}{$row}")->getFill()
            ->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('3B2B16');
        $row++;

        foreach ($filas as $f) {
            $sheet->fromArray([[
                $f['empleado'], $f['departamento'] ?? '—', $f['cargo'] ?? '—',
                Carbon::parse($f['fecha'])->format('d/m/Y'), $f['dia'], substr($f['hora'], 0, 5),
                $f['tipo'] ?? '—', $f['justificacion']['motivo'] ?? 'Pendiente',
            ]], null, "A{$row}");
            $row++;
        }

        foreach (range('A', $ultimaCol) as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $tempFile = tempnam(sys_get_temp_dir(), 'incompletas') . '.xlsx';
        $this->anchoColumnaLogo($sheet);
        (new Xlsx($spreadsheet))->save($tempFile);

        $this->logActividad('generado', 'Planillas', "Exportó a Excel las marcas incompletas del {$desde} al {$hasta} ({$filas->count()}).", null);

        return response()->download($tempFile, $this->nombreArchivo('MarcasIncompletas', '', 'xlsx'))
            ->deleteFileAfterSend(true);
    }

    public function pdf(Request $request)
    {
        [$desde, $hasta] = $this->periodo($request);
        $filas = $this->incompletas($desde, $hasta);
        $correlativo = $this->siguienteCorrelativo('marcas_incompletas');

        $pdf = Pdf::loadView('marcas-incompletas.pdf', compact('filas', 'desde', 'hasta', 'correlativo'))
            ->setPaper('letter', 'portrait');

        $this->logActividad('generado', 'Planillas', "Exportó a PDF las marcas incompletas del {$desde} al {$hasta} ({$filas->count()}).", null);

        return $pdf->download($this->nombreArchivo('MarcasIncompletas', '', 'pdf'));
    }

    private function periodo(Request $request): array
    {
        $data = $request->validate([
            'desde' => 'required|date',
            'hasta' => 'required|date|after_or_equal:desde',
        ]);

        return [$data['desde'], $data['hasta']];
    }

    // Días con una sola marca de empleados con código asignado, ordenados por
    // departamento, empleado y fecha.
    private function incompletas(string $desde, string $hasta)
    {
        $marcas = Marcacion::whereBetween('fecha', [$desde, $hasta])
            ->whereNotNull('id_empleado')
            ->orderBy('hora')
            ->get(['id_empleado', 'fecha', 'hora', 'tipo', 'device_ip'])
            ->groupBy(fn ($m) => $m->id_empleado . '|' . $m->fecha->toDateString())
            ->filter(fn ($g) => $g->count() === 1)
            ->map->first();

        $empleados = Empleado::with(['departamento:id,nombre', 'cargo:id,nombre'])
            ->whereIn('id', $marcas->pluck('id_empleado')->unique())
            ->get(['id', 'nombres', 'apellidos', 'id_departamento', 'id_cargo'])
            ->keyBy('id');

        $justificadas = MarcaJustificada::with('usuario:id,name')
            ->whereBetween('fecha', [$desde, $hasta])
            ->whereIn('id_empleado', $marcas->pluck('id_empleado')->unique())
            ->get()
            ->keyBy(fn ($j) => $j->id_empleado . '|' . $j->fecha->toDateString());

        return $marcas->map(function ($m, $clave) use ($empleados, $justificadas) {
            $e = $empleados->get($m->id_empleado);
            $j = $justificadas->get($clave);

            return [
                'id_empleado'   => $m->id_empleado,
                'empleado'      => trim("{$e?->nombres} {$e?->apellidos}"),
                'departamento'  => $e?->departamento?->nombre,
                'cargo'         => $e?->cargo?->nombre,
                'fecha'         => $m->fecha->toDateString(),
                'dia'           => self::DIAS[$m->fecha->dayOfWeek],
                'hora'          => $m->hora,
                'tipo'          => $m->tipo,
                'reloj'         => $m->device_ip,
                'justificacion' => $j ? $this->justificacionJson($j) : null,
            ];
        })
            ->sortBy([['departamento', 'asc'], ['empleado', 'asc'], ['fecha', 'asc']])
            ->values();
    }

    private function justificacionJson(MarcaJustificada $j): array
    {
        return [
            'id'      => $j->id,
            'motivo'  => $j->motivo,
            'usuario' => $j->usuario?->name,
            'fecha'   => $j->updated_at?->toDateTimeString(),
        ];
    }
}
