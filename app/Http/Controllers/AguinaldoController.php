<?php

namespace App\Http\Controllers;

use App\Models\AguinaldoExtra;
use App\Models\AguinaldoFijo;
use App\Models\DetallePlanilla;
use App\Models\Empleado;
use App\Traits\GeneraCorrelativo;
use App\Traits\NombraArchivos;
use App\Traits\SoloAdmin;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class AguinaldoController extends Controller
{
    use NombraArchivos, GeneraCorrelativo, SoloAdmin;

    // ─── List batches ────────────────────────────────────────────
    public function index()
    {
        $fijos = AguinaldoFijo::select(
                'nombre_aguinaldo', 'tipo_aguinaldo', 'concepto', 'estado', 'fecha_generada',
                DB::raw('COUNT(*) as empleados'),
                DB::raw('SUM(total_aguinaldo) as total')
            )
            ->groupBy('nombre_aguinaldo', 'tipo_aguinaldo', 'concepto', 'estado', 'fecha_generada')
            ->get()
            ->map(fn($r) => array_merge($r->toArray(), ['tabla' => 'Fijos']));

        $extras = AguinaldoExtra::select(
                'nombre_aguinaldo', 'tipo_aguinaldo', 'concepto', 'estado', 'fecha_generada',
                DB::raw('COUNT(*) as empleados'),
                DB::raw('SUM(total_aguinaldo) as total')
            )
            ->groupBy('nombre_aguinaldo', 'tipo_aguinaldo', 'concepto', 'estado', 'fecha_generada')
            ->get()
            ->map(fn($r) => array_merge($r->toArray(), ['tabla' => 'Extras']));

        // Merge batches by nombre_aguinaldo (Ambos appears in both tables)
        $merged = collect($fijos)->concat($extras)
            ->groupBy('nombre_aguinaldo')
            ->map(function ($rows, $nombre) {
                $first = $rows->first();
                return [
                    'nombre_aguinaldo' => $nombre,
                    'tipo_aguinaldo'   => $rows->count() > 1 ? 'Ambos' : $first['tipo_aguinaldo'],
                    'concepto'         => $first['concepto'],
                    'estado'           => $first['estado'],
                    'fecha_generada'   => $first['fecha_generada'],
                    'empleados'        => $rows->sum('empleados'),
                    'total'            => $rows->sum('total'),
                ];
            })
            ->values()
            ->sortByDesc('fecha_generada')
            ->values();

        return response()->json($merged);
    }

    // ─── Show batch detail ────────────────────────────────────────
    public function show($nombre)
    {
        $fijos  = AguinaldoFijo::where('nombre_aguinaldo', $nombre)
            ->orderBy('departamento')->orderBy('nombres')->orderBy('apellidos')->get();

        $extras = AguinaldoExtra::where('nombre_aguinaldo', $nombre)
            ->orderBy('departamento')->orderBy('nombres')->orderBy('apellidos')->get();

        abort_if($fijos->isEmpty() && $extras->isEmpty(), 404, 'Aguinaldo no encontrado.');

        $meta = ($fijos->first() ?? $extras->first());

        return response()->json([
            'nombre_aguinaldo' => $nombre,
            'tipo_aguinaldo'   => $meta->tipo_aguinaldo,
            'concepto'         => $meta->concepto,
            'estado'           => $meta->estado,
            'fecha_generada'   => $meta->fecha_generada,
            'fecha_corte'      => $meta->fecha_corte,
            'fijos'            => $fijos,
            'extras'           => $extras,
            'totales_fijos'    => $this->totalesFijos($fijos),
            'totales_extras'   => $this->totalesExtras($extras),
        ]);
    }

    // ─── Generate batch ──────────────────────────────────────────
    public function store(Request $request)
    {
        $request->validate([
            'nombre_aguinaldo' => 'required|string|max:50',
            // Cada concepto/tipo es una planilla independiente (ya no se generan "Ambos").
            'concepto'         => 'required|in:Aguinaldo,Catorceavo',
            'tipo_aguinaldo'   => 'required|in:Fijos,Extras',
            'fecha_generada'   => 'required|date',
            'fecha_corte'      => 'required|date',
        ]);

        $nombre = $request->nombre_aguinaldo;
        $tipo   = $request->tipo_aguinaldo;
        $concepto = $request->concepto;
        $fecha  = $request->fecha_generada;
        $corte  = $request->fecha_corte;

        // Prevent duplicate batch names
        $exists = AguinaldoFijo::where('nombre_aguinaldo', $nombre)->exists()
               || AguinaldoExtra::where('nombre_aguinaldo', $nombre)->exists();

        abort_if($exists, 422, 'Ya existe un aguinaldo con ese nombre.');

        return DB::transaction(function () use ($nombre, $tipo, $concepto, $fecha, $corte, $request) {
            // Fijos/Extras solo trae empleados de ese tipo_contrato; Ambos trae
            // los dos pero cada empleado se clasifica por su propio contrato
            // (nunca genera fijo Y extra para la misma persona).
            $empleados = Empleado::with(['informacionLaboral.banco', 'departamento', 'cargo'])
                ->whereHas('informacionLaboral', function ($q) use ($tipo) {
                    $q->where('estado', 'Activo');
                    if ($tipo !== 'Ambos') {
                        $q->where('tipo_contrato', $tipo === 'Fijos' ? 'Fijo' : 'Extra');
                    } else {
                        $q->whereIn('tipo_contrato', ['Fijo', 'Extra']);
                    }
                })
                ->get();

            $fechaCorte = Carbon::parse($corte);
            $countFijo  = 0;
            $countExtr  = 0;

            // Promedio de días de los extras: se calcula una sola vez para todo el lote.
            $promedios = $tipo !== 'Fijos' ? $this->promediosExtras($fechaCorte) : null;

            $requierePromedio = $empleados->contains(fn ($e) =>
                $e->informacionLaboral->tipo_contrato === 'Extra' && !$e->informacionLaboral->sin_promedio_dias);
            if ($promedios && $requierePromedio && $promedios['meses'] == 0) {
                abort(422, 'No hay planillas de extras entre el ' . $promedios['desde']->format('d/m/Y') . ' y el '
                    . $promedios['hasta']->format('d/m/Y') . ' para calcular el promedio de días.');
            }

            foreach ($empleados as $emp) {
                $il      = $emp->informacionLaboral;
                $esFijo  = $il->tipo_contrato === 'Fijo';
                $esExtra = $il->tipo_contrato === 'Extra';

                // Base de 360 dias (12 meses de 30 dias), igual que el calculo
                // manual en Excel: se toma el rango desde la fecha de inicio
                // hasta la fecha de corte elegida (ej. 31/12/AAAA, o un corte
                // distinto si se calcula un catorceavo).
                $fechaInicio = Carbon::parse($il->fecha_inicio);
                // max(0): desde Carbon 3 diffInDays es negativo si el empleado inició
                // después del corte; en ese caso no acumula días.
                $diasBase    = (int) max(0, min(360, $fechaInicio->diffInDays($fechaCorte, false)));

                if ($esFijo && ($tipo === 'Fijos' || $tipo === 'Ambos')) {
                    AguinaldoFijo::create([
                        'nombre_aguinaldo' => $nombre,
                        'departamento'     => $emp->departamento?->nombre ?? '',
                        'nombres'          => $emp->nombres,
                        'apellidos'        => $emp->apellidos,
                        'cargo'            => $emp->cargo?->nombre ?? '',
                        'cuenta'           => $il->num_cuenta,
                        'fecha_inicio'     => $il->fecha_inicio,
                        'salario_base'     => $il->salario_base,
                        'dias_trabajados'  => $diasBase,
                        'anticipo'         => 0,
                        'total_aguinaldo'  => round(($il->salario_base / 360) * $diasBase, 2),
                        'fecha_generada'   => $fecha,
                        'fecha_corte'      => $corte,
                        'estado'           => 'Activo',
                        'tipo_aguinaldo'   => $tipo,
                        'concepto'         => $concepto,
                        'id_empleado'      => $emp->id,
                        'id_info_laboral'  => $il->id,
                        'id_departamento'  => $emp->id_departamento,
                    ]);
                    $countFijo++;
                }

                // Un extra que entró después del corte no participa de este aguinaldo/catorceavo.
                if ($esExtra && ($tipo === 'Extras' || $tipo === 'Ambos') && $fechaInicio->lte($fechaCorte)) {
                    $sinPromedio = (bool) $il->sin_promedio_dias;
                    $promedio    = $sinPromedio ? null : $this->promedioEmpleado($promedios, $emp->id);
                    $diasProm    = $sinPromedio ? null : $this->diasPromediados($promedio);
                    $antiguedad  = $this->antiguedadExtra($fechaInicio, $fechaCorte);
                    $diario      = (float) $il->salario_diario;
                    [$subtotal, $total] = $this->calcularExtra($diario, $antiguedad, $diasProm, 0);

                    AguinaldoExtra::create([
                        'nombre_aguinaldo' => $nombre,
                        'departamento'     => $emp->departamento?->nombre ?? '',
                        'nombres'          => $emp->nombres,
                        'apellidos'        => $emp->apellidos,
                        'cuenta'           => $il->num_cuenta,
                        'fecha_inicio'     => $il->fecha_inicio,
                        'salario_base'     => $il->salario_base,
                        'diario'           => $diario,
                        'antiguedad'       => $antiguedad,
                        'dias_promedio'    => $diasProm,
                        'promedio_dias'    => $promedio,
                        'meses_promedio'   => $promedios['meses'],
                        'sin_promedio'     => $sinPromedio,
                        'subtotal'         => $subtotal,
                        'anticipos'        => 0,
                        'total_aguinaldo'  => $total,
                        'fecha_generada'   => $fecha,
                        'fecha_corte'      => $corte,
                        'periodo_desde'    => $promedios['desde']->toDateString(),
                        'periodo_hasta'    => $promedios['hasta']->toDateString(),
                        'estado'           => 'Activo',
                        'tipo_aguinaldo'   => $tipo,
                        'concepto'         => $concepto,
                        'id_empleado'      => $emp->id,
                        'id_info_laboral'  => $il->id,
                        'id_departamento'  => $emp->id_departamento,
                    ]);
                    $countExtr++;
                }
            }

            abort_if($countFijo + $countExtr === 0, 422, 'No se encontraron empleados activos del tipo seleccionado.');

            return response()->json([
                'nombre_aguinaldo' => $nombre,
                'tipo_aguinaldo'   => $tipo,
                'fijos_generados'  => $countFijo,
                'extras_generados' => $countExtr,
            ], 201);
        });
    }

    // ─── Update fijo record ───────────────────────────────────────
    public function updateFijo(Request $request, $id)
    {
        $registro = AguinaldoFijo::findOrFail($id);
        abort_if($registro->estado === 'Cerrado', 422, 'No se puede editar un aguinaldo cerrado.');

        $request->validate([
            'dias_trabajados' => 'sometimes|integer|min:0|max:360',
            'anticipo'        => 'sometimes|numeric|min:0',
        ]);

        $dias  = $request->input('dias_trabajados', $registro->dias_trabajados);
        $antic = (float) $request->input('anticipo', $registro->anticipo);
        $total = max(0, round(($registro->salario_base / 360) * $dias - $antic, 2));

        $registro->update([
            'dias_trabajados' => $dias,
            'anticipo'        => $antic,
            'total_aguinaldo' => $total,
        ]);

        return response()->json($registro->fresh());
    }

    // ─── Update extras record ─────────────────────────────────────
    public function updateExtra(Request $request, $id)
    {
        $registro = AguinaldoExtra::findOrFail($id);
        abort_if($registro->estado === 'Cerrado', 422, 'No se puede editar un aguinaldo cerrado.');

        $request->validate([
            'dias_promedio' => 'sometimes|nullable|integer|min:0|max:30',
            'antiguedad'    => 'sometimes|numeric|min:0|max:30',
            'anticipos'     => 'sometimes|numeric|min:0',
            'sin_promedio'  => 'sometimes|boolean',
        ]);

        $sinPromedio = $request->has('sin_promedio') ? $request->boolean('sin_promedio') : $registro->sin_promedio;
        $dias        = $sinPromedio ? null : (int) $request->input('dias_promedio', $registro->dias_promedio ?? 0);
        // Si la antigüedad (enviada o guardada) es la calculada por fechas, se usa el valor
        // exacto para no arrastrar el redondeo de 4 decimales; si no, es un ajuste manual.
        $exacta = $this->antiguedadExtra(Carbon::parse($registro->fecha_inicio), Carbon::parse($registro->fecha_corte));
        $valor  = $request->filled('antiguedad') ? (float) $request->antiguedad : (float) $registro->antiguedad;
        $antig  = abs($valor - round($exacta, 4)) < 0.00005 ? $exacta : $valor;
        $anticipos   = (float) $request->input('anticipos', $registro->anticipos);
        [$subtotal, $total] = $this->calcularExtra((float) $registro->diario, $antig, $dias, $anticipos);

        $registro->update([
            'sin_promedio'    => $sinPromedio,
            'dias_promedio'   => $dias,
            'antiguedad'      => $antig,
            'anticipos'       => $anticipos,
            'subtotal'        => $subtotal,
            'total_aguinaldo' => $total,
        ]);

        return response()->json($registro->fresh());
    }

    // ─── Close batch ──────────────────────────────────────────────
    public function cerrar($nombre)
    {
        $fijos  = AguinaldoFijo::where('nombre_aguinaldo', $nombre)->where('estado', 'Activo');
        $extras = AguinaldoExtra::where('nombre_aguinaldo', $nombre)->where('estado', 'Activo');

        abort_if($fijos->count() + $extras->count() === 0, 404, 'Aguinaldo no encontrado o ya cerrado.');

        $fijos->update(['estado'  => 'Cerrado']);
        $extras->update(['estado' => 'Cerrado']);

        return response()->json(['message' => 'Aguinaldo cerrado correctamente.']);
    }

    // ─── Delete batch ─────────────────────────────────────────────
    public function destroy(Request $request, $nombre)
    {
        $this->soloAdmin($request);

        $fijos  = AguinaldoFijo::where('nombre_aguinaldo', $nombre)->where('estado', 'Activo');
        $extras = AguinaldoExtra::where('nombre_aguinaldo', $nombre)->where('estado', 'Activo');

        abort_if($fijos->count() + $extras->count() === 0, 404, 'Aguinaldo no encontrado o ya cerrado.');

        $fijos->delete();
        $extras->delete();

        return response()->json(['message' => 'Aguinaldo eliminado.']);
    }

    // ─── Exportaciones (las mismas que en las planillas de pago) ──
    public function exportPdf($nombre)
    {
        [$fijos, $extras, $meta] = $this->cargarLote($nombre);

        $totalesFijos    = $this->totalesFijos($fijos);
        $totalesExtras   = $this->totalesExtras($extras);
        $correlativo     = $this->siguienteCorrelativo($this->tipoDocumento($meta), $meta->id ?? null);

        $pdf = Pdf::loadView('aguinaldo.pdf', compact(
            'nombre', 'fijos', 'extras', 'meta', 'totalesFijos', 'totalesExtras', 'correlativo'
        ))->setPaper('letter', 'landscape');

        return $pdf->download($this->sanitizarNombreArchivo($nombre) . '.pdf');
    }

    // Excel general con las mismas columnas que las planillas de Excel que usa RRHH
    // (fijos: días del año y aguinaldo a pagar; extras: antigüedad, subtotal y días prom.).
    public function exportExcel($nombre)
    {
        [$fijos, $extras, $meta] = $this->cargarLote($nombre);

        $spreadsheet = new Spreadsheet();
        $spreadsheet->removeSheetByIndex(0);

        if ($fijos->isNotEmpty()) {
            $this->hojaExcel($spreadsheet->createSheet(), 'Fijos', strtoupper($nombre), $this->infoLote($meta, $fijos->count()), [
                ['Nombre', fn ($r) => "{$r->nombres} {$r->apellidos}", 'texto'],
                ['Cuenta', fn ($r) => $r->cuenta, 'texto'],
                ['Cargo', fn ($r) => $r->cargo, 'texto'],
                ['F. Inicio', fn ($r) => $r->fecha_inicio?->format('d/m/Y'), 'texto'],
                ['Salario Mensual', fn ($r) => $r->salario_base, 'monto', true],
                ['Días Año', fn ($r) => $r->dias_trabajados, 'numero'],
                ['Anticipo', fn ($r) => $r->anticipo, 'monto', true],
                [$meta->concepto === 'Catorceavo' ? 'Catorceavo a Pagar' : 'Aguinaldo a Pagar', fn ($r) => $r->total_aguinaldo, 'monto', true],
            ], $fijos);
        }

        if ($extras->isNotEmpty()) {
            $this->hojaExcel($spreadsheet->createSheet(), 'Extras', strtoupper($nombre), $this->infoLote($meta, $extras->count()), [
                ['Nombre', fn ($r) => "{$r->nombres} {$r->apellidos}", 'texto'],
                ['Cuenta', fn ($r) => $r->cuenta, 'texto'],
                ['Cargo', fn ($r) => $r->empleado?->cargo?->nombre, 'texto'],
                ['F. Inicio', fn ($r) => $r->fecha_inicio?->format('d/m/Y'), 'texto'],
                ['Sal. Mensual', fn ($r) => $r->salario_base, 'monto'],
                ['Sal. Diario', fn ($r) => $r->diario, 'monto'],
                ['Antigüedad', fn ($r) => $r->antiguedad, 'monto'],
                ['Subtotal', fn ($r) => $r->subtotal, 'monto', true],
                ['Días Prom.', fn ($r) => $r->sin_promedio ? 'N/A' : $r->dias_promedio, 'numero'],
                ['Anticipos', fn ($r) => $r->anticipos, 'monto', true],
                ['Total a Pagar', fn ($r) => $r->total_aguinaldo, 'monto', true],
            ], $extras);
        }

        return $this->descargarExcel($spreadsheet, $this->sanitizarNombreArchivo($nombre) . '.xlsx');
    }

    // Archivo para el banco: solo Empleado y Total, orden alfabético, únicamente
    // los que cobran por transferencia (tienen cuenta registrada).
    public function exportPagoGeneralExcel($nombre)
    {
        [$fijos, $extras, $meta] = $this->cargarLote($nombre);

        $filas = $this->filasPago($fijos, $extras, 'banco')
            ->sortBy('empleado', SORT_NATURAL | SORT_FLAG_CASE)->values();

        $spreadsheet = new Spreadsheet();
        $this->hojaExcel($spreadsheet->getActiveSheet(), 'Pago', strtoupper($nombre) . ' — GENERAR PAGO',
            $this->infoLote($meta, $filas->count()), [
                ['Empleado', fn ($r) => $r->empleado, 'texto'],
                ['Total a Pagar', fn ($r) => $r->total, 'monto', true],
            ], $filas, agrupar: false);

        return $this->descargarExcel($spreadsheet, 'Pago (' . $this->sanitizarNombreArchivo($nombre) . ').xlsx');
    }

    public function exportBancosExcel($nombre)
    {
        return $this->exportPagoExcel($nombre, 'banco', 'Bancos', 'PAGO POR TRANSFERENCIA BANCARIA');
    }

    public function exportBancosPdf($nombre)
    {
        return $this->exportPagoPdf($nombre, 'banco', 'Bancos', 'PAGO POR TRANSFERENCIA BANCARIA');
    }

    public function exportChequesExcel($nombre)
    {
        return $this->exportPagoExcel($nombre, 'cheque', 'Cheques', 'PAGO POR CHEQUE');
    }

    public function exportChequesPdf($nombre)
    {
        return $this->exportPagoPdf($nombre, 'cheque', 'Cheques', 'PAGO POR CHEQUE');
    }

    private function exportPagoExcel($nombre, string $metodo, string $sufijo, string $titulo)
    {
        [$fijos, $extras, $meta] = $this->cargarLote($nombre);
        $filas = $this->filasPago($fijos, $extras, $metodo);

        $columnas = [['Empleado', fn ($r) => $r->empleado, 'texto']];
        if ($metodo === 'banco') {
            $columnas[] = ['Cuenta', fn ($r) => $r->cuenta, 'texto'];
        }
        array_push($columnas,
            ['Monto', fn ($r) => $r->monto, 'monto', true],
            ['Anticipos', fn ($r) => $r->anticipos, 'monto', true],
            ['Total a Pagar', fn ($r) => $r->total, 'monto', true],
        );

        $spreadsheet = new Spreadsheet();
        $this->hojaExcel($spreadsheet->getActiveSheet(), $sufijo, strtoupper($nombre) . ' — ' . $titulo,
            $this->infoLote($meta, $filas->count()), $columnas, $filas);

        return $this->descargarExcel($spreadsheet, "{$sufijo} (" . $this->sanitizarNombreArchivo($nombre) . ').xlsx');
    }

    private function exportPagoPdf($nombre, string $metodo, string $sufijo, string $titulo)
    {
        [$fijos, $extras, $meta] = $this->cargarLote($nombre);
        $filas       = $this->filasPago($fijos, $extras, $metodo);
        $correlativo = $this->siguienteCorrelativo($this->tipoDocumento($meta) . ($metodo === 'banco' ? '_bancos' : '_cheques'), $meta->id);
        $conCuenta   = $metodo === 'banco';

        $pdf = Pdf::loadView('aguinaldo.pago-pdf', compact('nombre', 'meta', 'filas', 'titulo', 'correlativo', 'conCuenta'))
            ->setPaper('letter', 'portrait');

        return $pdf->download("{$sufijo} (" . $this->sanitizarNombreArchivo($nombre) . ').pdf');
    }

    private function cargarLote($nombre): array
    {
        $fijos  = AguinaldoFijo::where('nombre_aguinaldo', $nombre)
            ->orderBy('departamento')->orderBy('nombres')->orderBy('apellidos')->get();
        $extras = AguinaldoExtra::with('empleado.cargo:id,nombre')->where('nombre_aguinaldo', $nombre)
            ->orderBy('departamento')->orderBy('nombres')->orderBy('apellidos')->get();

        abort_if($fijos->isEmpty() && $extras->isEmpty(), 404, 'Aguinaldo no encontrado.');

        return [$fijos, $extras, $fijos->first() ?? $extras->first()];
    }

    // Filas uniformes (fijos y extras) para los archivos de pago. Bancos = tienen
    // número de cuenta guardado en el lote (capturado de la ficha al generarlo);
    // Cheques = sin cuenta. Mismo criterio que en las planillas de pago.
    private function filasPago($fijos, $extras, ?string $metodo)
    {
        $filas = $fijos->map(fn ($r) => (object) [
            'departamento' => $r->departamento, 'empleado' => trim("{$r->nombres} {$r->apellidos}"), 'cuenta' => $r->cuenta,
            'monto' => (float) $r->total_aguinaldo + (float) $r->anticipo, 'anticipos' => (float) $r->anticipo, 'total' => (float) $r->total_aguinaldo,
        ])->concat($extras->map(fn ($r) => (object) [
            'departamento' => $r->departamento, 'empleado' => trim("{$r->nombres} {$r->apellidos}"), 'cuenta' => $r->cuenta,
            'monto' => (float) $r->total_aguinaldo + (float) $r->anticipos, 'anticipos' => (float) $r->anticipos, 'total' => (float) $r->total_aguinaldo,
        ]));

        if ($metodo !== null) {
            $filas = $filas->filter(fn ($f) => $metodo === 'banco' ? filled($f->cuenta) : blank($f->cuenta));
        }

        return $filas->sortBy([['departamento', 'asc'], ['empleado', 'asc']])->values();
    }

    private function tipoDocumento($meta): string
    {
        return $meta->concepto === 'Catorceavo' ? 'catorceavo' : 'aguinaldo';
    }

    private function infoLote($meta, int $empleados): string
    {
        return sprintf('%s %s   |   Corte: %s   |   Estado: %s   |   Empleados: %d',
            $meta->concepto, $meta->tipo_aguinaldo,
            $meta->fecha_corte ? Carbon::parse($meta->fecha_corte)->format('d/m/Y') : '—',
            $meta->estado, $empleados);
    }

    // Hoja con el formato de las planillas: encabezado del hotel, filas agrupadas por
    // departamento con subtotales y total general. Columnas: [título, valor, tipo, ¿suma?].
    private function hojaExcel($sheet, string $nombreHoja, string $titulo, string $info, array $columnas, $filas, bool $agrupar = true): void
    {
        $sheet->setTitle($nombreHoja);
        $ultima = Coordinate::stringFromColumnIndex(count($columnas));
        $centro = Alignment::HORIZONTAL_CENTER;

        foreach ([1 => ['INVERSIONES Y SERVICIOS S.A - HOTEL PALMA REAL', 14], 2 => [$titulo, 12], 3 => [$info, null]] as $fila => [$texto, $tam]) {
            $sheet->mergeCells("A{$fila}:{$ultima}{$fila}");
            $sheet->setCellValue("A{$fila}", $texto);
            $sheet->getStyle("A{$fila}")->getAlignment()->setHorizontal($centro);
            if ($tam) {
                $sheet->getStyle("A{$fila}")->getFont()->setBold(true)->setSize($tam);
            }
        }

        $row = 5;
        foreach ($columnas as $i => [$tituloCol]) {
            $sheet->setCellValue(Coordinate::stringFromColumnIndex($i + 1) . $row, $tituloCol);
        }
        $this->pintarFila($sheet, "A{$row}:{$ultima}{$row}", '3B2B16', 'FFFFFF');
        $row++;

        $escribirFila = function ($valores, ?string $etiqueta = null, ?string $color = null) use ($sheet, $columnas, $ultima, &$row) {
            foreach ($columnas as $i => $col) {
                $celda = Coordinate::stringFromColumnIndex($i + 1) . $row;
                $valor = $etiqueta !== null ? ($i === 0 ? $etiqueta : (($col[3] ?? false) ? $valores($col) : null)) : $col[1]($valores);
                if ($valor === null || $valor === '') {
                    continue;
                }
                in_array($col[2], ['monto', 'numero']) && is_numeric($valor)
                    ? $sheet->setCellValueExplicit($celda, round((float) $valor, 2), DataType::TYPE_NUMERIC)
                    : $sheet->setCellValueExplicit($celda, (string) $valor, DataType::TYPE_STRING);
                if ($col[2] === 'monto') {
                    $sheet->getStyle($celda)->getNumberFormat()->setFormatCode('#,##0.00');
                }
            }
            if ($color) {
                $this->pintarFila($sheet, "A{$row}:{$ultima}{$row}", $color);
            }
            $row++;
        };
        $sumar = fn ($grupo) => fn ($col) => $grupo->sum(fn ($r) => (float) $col[1]($r));

        $grupos = $agrupar ? $filas->groupBy('departamento') : collect(['' => $filas]);
        foreach ($grupos as $departamento => $grupo) {
            if ($agrupar) {
                $sheet->setCellValue("A{$row}", $departamento);
                $sheet->mergeCells("A{$row}:{$ultima}{$row}");
                $this->pintarFila($sheet, "A{$row}", 'F8F2DF', '3B2B16');
                $row++;
            }
            foreach ($grupo as $r) {
                $escribirFila($r);
            }
            if ($agrupar) {
                $escribirFila($sumar($grupo), "SUBTOTAL: {$departamento}", 'EEE3C3');
            }
        }
        $escribirFila($sumar($filas), 'TOTAL GENERAL', 'B9921A');

        foreach (range(1, count($columnas)) as $i) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($i))->setAutoSize(true);
        }
    }

    private function pintarFila($sheet, string $rango, string $fondo, ?string $fuente = null): void
    {
        $estilo = $sheet->getStyle($rango);
        $estilo->getFont()->setBold(true);
        if ($fuente) {
            $estilo->getFont()->getColor()->setRGB($fuente);
        }
        $estilo->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($fondo);
    }

    private function descargarExcel(Spreadsheet $spreadsheet, string $archivo)
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'aguinaldo') . '.xlsx';
        (new Xlsx($spreadsheet))->save($tempFile);

        return response()->download($tempFile, $archivo)->deleteFileAfterSend(true);
    }

    // ─── Quincenas usadas en el promedio de un extra ─────────────
    // Muestra de dónde sale "Días prom." (equivale a la fila del Excel de
    // Promedio de Días Trabajados): días por quincena, tope de 15 y promedio.
    public function quincenasExtra($id)
    {
        $registro = AguinaldoExtra::findOrFail($id);
        $promedios = $this->promediosExtras(Carbon::parse($registro->fecha_corte));
        $dias = $promedios['dias'][$registro->id_empleado] ?? [];

        $quincenas = collect($promedios['quincenas'])->map(fn ($fecha) => [
            'fecha'   => $fecha,
            'dias'    => $dias[$fecha] ?? null,
            'contado' => isset($dias[$fecha]) ? min(15, $dias[$fecha]) : 0,
        ])->values();

        return response()->json([
            'periodo_desde'  => $promedios['desde']->toDateString(),
            'periodo_hasta'  => $promedios['hasta']->toDateString(),
            'meses'          => $promedios['meses'],
            'quincenas'      => $quincenas,
            'total_contado'  => $quincenas->sum('contado'),
            'promedio'       => $this->promedioEmpleado($promedios, $registro->id_empleado),
            'dias_promedio'  => $this->diasPromediados($this->promedioEmpleado($promedios, $registro->id_empleado)),
        ]);
    }

    // ─── Cálculo de extras (mismas fórmulas que la planilla en Excel) ──
    // Período: los 12 meses que terminan en la fecha de corte (catorceavo:
    // julio-junio; aguinaldo: enero-diciembre). Por quincena cuentan como
    // máximo 15 días. Divisor: meses del período con planillas de extras
    // (quincenas ÷ 2, la celda A2 del Excel), igual para todos los empleados.
    private function promediosExtras(Carbon $corte): array
    {
        $hasta = $corte->copy()->startOfDay();
        $desde = $hasta->copy()->subYear()->addDay();

        $filas = DetallePlanilla::query()
            ->join('cabecera_planillas as c', 'c.id', '=', 'detalle_planillas.id_cabecera_planilla')
            ->where('c.tipo_planilla', 'Extras')
            ->whereBetween('c.fecha_generada', [$desde->toDateString(), $hasta->toDateString()])
            ->selectRaw('detalle_planillas.id_empleado, DATE(c.fecha_generada) as fecha, SUM(detalle_planillas.dias_trabajados) as dias')
            ->groupBy('detalle_planillas.id_empleado', DB::raw('DATE(c.fecha_generada)'))
            ->get();

        $quincenas = DB::table('cabecera_planillas')
            ->where('tipo_planilla', 'Extras')
            ->whereBetween('fecha_generada', [$desde->toDateString(), $hasta->toDateString()])
            ->selectRaw('DISTINCT DATE(fecha_generada) as fecha')
            ->orderBy('fecha')
            ->pluck('fecha')
            ->all();

        $dias = [];
        foreach ($filas as $f) {
            $dias[$f->id_empleado][$f->fecha] = (int) $f->dias;
        }

        // Cada mes tiene 2 quincenas: con meses completos equivale a contar meses
        // (22 quincenas → 11, como la celda A2), y un mes a medias cuenta como 0.5.
        $meses = count($quincenas) / 2;

        return compact('desde', 'hasta', 'quincenas', 'meses', 'dias');
    }

    // Promedio mensual exacto (antes de cortar decimales).
    private function promedioEmpleado(array $promedios, int $idEmpleado): float
    {
        if ($promedios['meses'] == 0) {
            return 0;
        }

        $total = collect($promedios['dias'][$idEmpleado] ?? [])->sum(fn ($d) => min(15, $d));

        return round($total / $promedios['meses'], 3);
    }

    // Días promediados: se cortan los decimales (17.9 → 17), máximo 30.
    private function diasPromediados(?float $promedio): int
    {
        return (int) min(30, floor(round((float) $promedio, 6)));
    }

    // Antigüedad en "días de 30": 30 si al corte cumple 360 días o más; si no,
    // proporcional (días al corte / 360 * 30). Días calendario, como en el Excel.
    private function antiguedadExtra(Carbon $inicio, Carbon $corte): float
    {
        $dias = max(0, $inicio->diffInDays($corte, false));

        // Sin redondear: el total usa el valor exacto, como el Excel (la columna lo guarda con 4 decimales).
        return $dias >= 360 ? 30.0 : $dias / 360 * 30;
    }

    // Subtotal = diario × antigüedad. Total = días prom. / 30 × subtotal − anticipos.
    // Sin promedio (trabaja todos los días): total = subtotal − anticipos.
    private function calcularExtra(float $diario, float $antiguedad, ?int $diasProm, float $anticipos): array
    {
        $subtotal = $diario * $antiguedad;
        $factor   = $diasProm === null ? 1 : $diasProm / 30;

        return [round($subtotal, 2), max(0, round($factor * $subtotal - $anticipos, 2))];
    }

    // ─── Helpers ─────────────────────────────────────────────────
    private function totalesFijos($fijos): array
    {
        return [
            'dias_trabajados' => $fijos->sum('dias_trabajados'),
            'salario_base'    => $fijos->sum('salario_base'),
            'anticipo'        => $fijos->sum('anticipo'),
            'total_aguinaldo' => $fijos->sum('total_aguinaldo'),
        ];
    }

    private function totalesExtras($extras): array
    {
        return [
            'subtotal'        => $extras->sum('subtotal'),
            'anticipos'       => $extras->sum('anticipos'),
            'total_aguinaldo' => $extras->sum('total_aguinaldo'),
        ];
    }
}
