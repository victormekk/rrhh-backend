<?php

namespace App\Http\Controllers;

use App\Models\Empleado;
use App\Models\HistorialLaboral;
use App\Traits\EncabezadoExcel;
use App\Traits\LogsActividad;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use App\Rules\NoEs29Febrero;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class HistorialLaboralController extends Controller
{
    use LogsActividad, EncabezadoExcel;

    // "Cargo: A → B. Departamento: X → Y" (solo lo que cambió)
    private static function detallePuesto(HistorialLaboral $m): string
    {
        $partes = [];
        if ($m->cargo_anterior !== $m->cargo_nuevo) {
            $partes[] = "Cargo: {$m->cargo_anterior} → {$m->cargo_nuevo}";
        }
        if ($m->departamento_anterior !== $m->departamento_nuevo) {
            $partes[] = "Departamento: {$m->departamento_anterior} → {$m->departamento_nuevo}";
        }
        return implode('. ', $partes);
    }

    // ─── Historial de un empleado (línea de tiempo en su ficha) ───────────────
    // Incluye sus incidencias (título, fecha y grado). No se copian a historial_laboral:
    // se leen de la tabla de incidencias, así una edición o borrado se refleja solo.
    public function porEmpleado($id)
    {
        $empleado = Empleado::findOrFail($id);

        $movimientos = $empleado->historialLaboral()->with('usuario:id,name')->get()
            ->map(fn ($m) => $m->toArray());

        $incidencias = $empleado->incidencias()->with('usuario:id,name')->get()
            ->map(fn ($i) => [
                'id'          => "inc-{$i->id}",
                'tipo_evento' => 'Incidencia',
                'fecha'       => Carbon::parse($i->fecha_incidencia)->toDateString(),
                'titulo'      => $i->titulo,
                'grado'       => $i->grado,
                'usuario'     => $i->usuario ? ['id' => $i->usuario->id, 'name' => $i->usuario->name] : null,
            ]);

        // Orden cronológico; en la misma fecha, los movimientos laborales primero
        return response()->json(
            $movimientos->concat($incidencias)
                ->sortBy([['fecha', 'asc'], [fn ($a, $b) => ($a['tipo_evento'] === 'Incidencia') <=> ($b['tipo_evento'] === 'Incidencia')]])
                ->values()
        );
    }

    // ─── Dar de baja: siempre con fecha, motivo y estado de la liquidación ────
    public function cese(Request $request, $id)
    {
        $empleado = Empleado::with('informacionLaboral')->findOrFail($id);
        $il = $empleado->informacionLaboral;

        if ($il->estado === 'Inactivo') {
            return response()->json(['message' => 'El empleado ya está inactivo.'], 422);
        }

        $request->validate([
            'fecha_cese'    => ['required', 'date', 'after_or_equal:' . $il->fecha_inicio->format('Y-m-d')],
            'motivo_cese'   => ['required', Rule::in(HistorialLaboral::MOTIVOS_CESE)],
            'liquidacion'   => ['required', Rule::in(HistorialLaboral::LIQUIDACION)],
            'observaciones' => 'nullable|string|max:500',
        ], [
            'fecha_cese.after_or_equal' => 'La fecha de cese no puede ser anterior a la fecha de inicio (' . $il->fecha_inicio->format('d/m/Y') . ').',
        ]);

        DB::transaction(function () use ($request, $empleado, $il) {
            $il->update([
                'estado'      => 'Inactivo',
                'fecha_cese'  => $request->fecha_cese,
                'motivo_cese' => $request->motivo_cese,
            ]);

            HistorialLaboral::create([
                'id_empleado'            => $empleado->id,
                'tipo_evento'            => HistorialLaboral::CESE,
                'fecha'                  => $request->fecha_cese,
                'tipo_contrato_anterior' => $il->tipo_contrato,
                'fecha_inicio_anterior'  => $il->fecha_inicio,
                'motivo_cese'            => $request->motivo_cese,
                'liquidacion'            => $request->liquidacion,
                'fecha_liquidacion'      => $request->liquidacion === 'Sí' ? now()->toDateString() : null,
                'observaciones'          => $request->observaciones,
                'id_usuario'             => $request->user()->id,
            ]);
        });

        $this->logActividad('eliminado', 'Empleados',
            "Empleado {$empleado->nombres} {$empleado->apellidos} dado de baja ({$request->motivo_cese}).", $empleado->id);

        return response()->json(['message' => 'Empleado dado de baja correctamente.']);
    }

    // ─── Reintegro: nueva fecha de inicio y motivo obligatorio ────────────────
    public function reintegro(Request $request, $id)
    {
        $empleado = Empleado::with('informacionLaboral')->findOrFail($id);
        $il = $empleado->informacionLaboral;

        if ($il->estado !== 'Inactivo') {
            return response()->json(['message' => 'Solo se puede reintegrar a un empleado inactivo.'], 422);
        }

        $reglasFecha = ['required', 'date', new NoEs29Febrero];
        if ($il->fecha_cese) {
            $reglasFecha[] = 'after:' . $il->fecha_cese->format('Y-m-d');
        }

        $request->validate([
            'fecha_inicio'  => $reglasFecha,
            'tipo_contrato' => ['required', Rule::in(['Fijo', 'Extra'])],
            'motivo'        => 'required|string|min:5|max:500',
        ], [
            'fecha_inicio.after' => 'La nueva fecha de inicio debe ser posterior a la fecha de cese (' . $il->fecha_cese?->format('d/m/Y') . ').',
            'motivo.required'    => 'Indica el motivo del reintegro.',
            'motivo.min'         => 'Describe un poco más el motivo del reintegro.',
        ]);

        DB::transaction(function () use ($request, $empleado, $il) {
            HistorialLaboral::create([
                'id_empleado'            => $empleado->id,
                'tipo_evento'            => HistorialLaboral::REINTEGRO,
                'fecha'                  => $request->fecha_inicio,
                'tipo_contrato_anterior' => $il->tipo_contrato,
                'tipo_contrato_nuevo'    => $request->tipo_contrato,
                'fecha_inicio_anterior'  => $il->fecha_inicio,
                'fecha_inicio_nueva'     => $request->fecha_inicio,
                'observaciones'          => $request->motivo,
                'id_usuario'             => $request->user()->id,
            ]);

            $il->update([
                'estado'            => 'Activo',
                'tipo_contrato'     => $request->tipo_contrato,
                'fecha_inicio'      => $request->fecha_inicio,
                'fecha_cese'        => null,
                'motivo_cese'       => null,
                'sin_promedio_dias' => $request->tipo_contrato === 'Extra' && $il->sin_promedio_dias,
            ]);
        });

        $this->logActividad('editado', 'Empleados',
            "Empleado {$empleado->nombres} {$empleado->apellidos} reintegrado como {$request->tipo_contrato}.", $empleado->id);

        return response()->json(['message' => 'Empleado reintegrado correctamente.']);
    }

    // ─── Actualizar la liquidación (p. ej. de Pendiente a Sí cuando contabilidad avisa) ──
    public function liquidacion(Request $request, $id)
    {
        $evento = HistorialLaboral::with('empleado:id,nombres,apellidos')->findOrFail($id);

        if ($evento->liquidacion === null) {
            return response()->json(['message' => 'Este movimiento no lleva liquidación.'], 422);
        }

        $request->validate(['liquidacion' => ['required', Rule::in(HistorialLaboral::LIQUIDACION)]]);

        $evento->update([
            'liquidacion'       => $request->liquidacion,
            'fecha_liquidacion' => $request->liquidacion === 'Sí' ? now()->toDateString() : null,
        ]);

        $this->logActividad('editado', 'Empleados',
            "Liquidación de {$evento->empleado->nombres} {$evento->empleado->apellidos} marcada como {$request->liquidacion}.", $evento->id_empleado);

        return response()->json($evento->fresh('usuario:id,name'));
    }

    // ─── Reporte: Movimientos de Personal ──────────────────────────────────────
    private function consulta(Request $request)
    {
        return HistorialLaboral::query()
            ->with(['empleado:id,nombres,apellidos,cedula,id_departamento', 'empleado.departamento:id,nombre', 'usuario:id,name'])
            ->when($request->desde, fn ($q, $d) => $q->whereDate('fecha', '>=', $d))
            ->when($request->hasta, fn ($q, $h) => $q->whereDate('fecha', '<=', $h))
            ->when($request->tipo_evento, fn ($q, $t) => $q->where('tipo_evento', $t))
            ->when($request->motivo_cese, fn ($q, $m) => $q->where('motivo_cese', $m))
            ->when($request->liquidacion, fn ($q, $l) => $q->where('liquidacion', $l))
            ->when($request->search, function ($q, $s) {
                $q->whereHas('empleado', fn ($e) => $e->where(fn ($w) => $w
                    ->where('nombres', 'like', "%{$s}%")
                    ->orWhere('apellidos', 'like', "%{$s}%")
                    ->orWhere('cedula', 'like', "%{$s}%")));
            })
            ->orderByDesc('fecha')
            ->orderByDesc('id');
    }

    public function index(Request $request)
    {
        return response()->json($this->consulta($request)->paginate($request->input('per_page', 20)));
    }

    public function catalogos()
    {
        return response()->json([
            'tipos_evento' => HistorialLaboral::TIPOS_EVENTO,
            'motivos_cese' => HistorialLaboral::MOTIVOS_CESE,
            'liquidacion'  => HistorialLaboral::LIQUIDACION,
        ]);
    }

    public function exportarExcel(Request $request)
    {
        $movimientos = $this->consulta($request)->get();

        $columnas = [
            'A' => 'Fecha', 'B' => 'Movimiento', 'C' => 'Empleado', 'D' => 'DNI', 'E' => 'Departamento',
            'F' => 'Contrato', 'G' => 'Fecha de inicio anterior', 'H' => 'Nueva fecha de inicio',
            'I' => 'Motivo de cese', 'J' => 'Liquidación', 'K' => 'Observaciones', 'L' => 'Registrado por',
        ];
        $ultimaCol = 'L';

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Movimientos de Personal');

        $periodo = ($request->desde || $request->hasta)
            ? sprintf('Del %s al %s   |   ',
                $request->desde ? Carbon::parse($request->desde)->format('d/m/Y') : 'inicio',
                $request->hasta ? Carbon::parse($request->hasta)->format('d/m/Y') : 'hoy')
            : '';

        // Logo a la izquierda y los títulos a su derecha (ver App\Traits\EncabezadoExcel)
        $this->encabezadoExcel($sheet, $ultimaCol, [
            ['INVERSIONES Y SERVICIOS S.A - HOTEL PALMA REAL', 14],
            ['MOVIMIENTOS DE PERSONAL', 12],
            [sprintf('%sGenerado: %s   |   Movimientos: %d', $periodo, now()->format('d/m/Y'), $movimientos->count()), null],
        ]);

        $row = 5;
        foreach ($columnas as $col => $titulo) {
            $sheet->setCellValue("{$col}{$row}", $titulo);
        }
        $sheet->getStyle("A{$row}:{$ultimaCol}{$row}")->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $sheet->getStyle("A{$row}:{$ultimaCol}{$row}")->getFill()
            ->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('3B2B16');
        $row++;

        $f = fn ($d) => $d ? Carbon::parse($d)->format('d/m/Y') : '';

        foreach ($movimientos as $m) {
            $contrato = match (true) {
                $m->tipo_contrato_anterior && $m->tipo_contrato_nuevo && $m->tipo_contrato_anterior !== $m->tipo_contrato_nuevo
                    => "{$m->tipo_contrato_anterior} → {$m->tipo_contrato_nuevo}",
                default => $m->tipo_contrato_nuevo ?? $m->tipo_contrato_anterior ?? '',
            };
            $sheet->setCellValue("A{$row}", $f($m->fecha));
            $sheet->setCellValue("B{$row}", $m->tipo_evento);
            $sheet->setCellValue("C{$row}", trim("{$m->empleado?->nombres} {$m->empleado?->apellidos}"));
            $sheet->setCellValueExplicit("D{$row}", $m->empleado?->cedula ?? '', \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            $sheet->setCellValue("E{$row}", $m->empleado?->departamento?->nombre ?? '');
            $sheet->setCellValue("F{$row}", $contrato);
            $sheet->setCellValue("G{$row}", $f($m->fecha_inicio_anterior));
            $sheet->setCellValue("H{$row}", $f($m->fecha_inicio_nueva));
            $sheet->setCellValue("I{$row}", $m->motivo_cese ?? '');
            $sheet->setCellValue("J{$row}", $m->liquidacion
                ? $m->liquidacion . ($m->fecha_liquidacion ? ' (' . $f($m->fecha_liquidacion) . ')' : '')
                : '');
            $sheet->setCellValue("K{$row}", trim(implode('. ', array_filter([
                $m->tipo_evento === HistorialLaboral::CAMBIO_PUESTO ? self::detallePuesto($m) : null,
                $m->observaciones,
            ]))));
            $sheet->setCellValue("L{$row}", $m->usuario?->name ?? '');
            $row++;
        }

        foreach (array_keys($columnas) as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
        $sheet->getColumnDimension('K')->setAutoSize(false)->setWidth(50);
        $sheet->getStyle("K6:K" . max(6, $row - 1))->getAlignment()->setWrapText(true);

        $tempFile = tempnam(sys_get_temp_dir(), 'movimientos') . '.xlsx';
        $this->anchoColumnaLogo($sheet);
        (new Xlsx($spreadsheet))->save($tempFile);

        $this->logActividad('generado', 'Empleados',
            "Exportó {$movimientos->count()} movimiento(s) de personal a Excel.", null);

        return response()->download($tempFile, 'MovimientosPersonal_' . now()->format('dmY') . '.xlsx')
            ->deleteFileAfterSend(true);
    }
}
