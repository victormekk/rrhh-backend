<?php

namespace App\Http\Controllers;

use App\Models\AguinaldoExtra;
use App\Models\AguinaldoFijo;
use App\Models\DetallePlanilla;
use App\Models\Empleado;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AguinaldoController extends Controller
{
    // ─── List batches ────────────────────────────────────────────
    public function index()
    {
        $fijos = AguinaldoFijo::select(
                'nombre_aguinaldo', 'tipo_aguinaldo', 'estado', 'fecha_generada',
                DB::raw('COUNT(*) as empleados'),
                DB::raw('SUM(total_aguinaldo) as total')
            )
            ->groupBy('nombre_aguinaldo', 'tipo_aguinaldo', 'estado', 'fecha_generada')
            ->get()
            ->map(fn($r) => array_merge($r->toArray(), ['tabla' => 'Fijos']));

        $extras = AguinaldoExtra::select(
                'nombre_aguinaldo', 'tipo_aguinaldo', 'estado', 'fecha_generada',
                DB::raw('COUNT(*) as empleados'),
                DB::raw('SUM(total_aguinaldo) as total')
            )
            ->groupBy('nombre_aguinaldo', 'tipo_aguinaldo', 'estado', 'fecha_generada')
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
            ->orderBy('departamento')->orderBy('apellidos')->get();

        $extras = AguinaldoExtra::where('nombre_aguinaldo', $nombre)
            ->orderBy('departamento')->orderBy('apellidos')->get();

        abort_if($fijos->isEmpty() && $extras->isEmpty(), 404, 'Aguinaldo no encontrado.');

        $meta = ($fijos->first() ?? $extras->first());

        return response()->json([
            'nombre_aguinaldo' => $nombre,
            'tipo_aguinaldo'   => $meta->tipo_aguinaldo,
            'estado'           => $meta->estado,
            'fecha_generada'   => $meta->fecha_generada,
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
            'tipo_aguinaldo'   => 'required|in:Fijos,Extras,Ambos',
            'fecha_generada'   => 'required|date',
        ]);

        $nombre = $request->nombre_aguinaldo;
        $tipo   = $request->tipo_aguinaldo;
        $fecha  = $request->fecha_generada;

        // Prevent duplicate batch names
        $exists = ($tipo !== 'Extras' && AguinaldoFijo::where('nombre_aguinaldo', $nombre)->exists())
               || ($tipo !== 'Fijos'  && AguinaldoExtra::where('nombre_aguinaldo', $nombre)->exists());

        abort_if($exists, 422, 'Ya existe un aguinaldo con ese nombre.');

        return DB::transaction(function () use ($nombre, $tipo, $fecha, $request) {
            $empleados = Empleado::with(['informacionLaboral.banco', 'departamento'])
                ->whereHas('informacionLaboral', fn($q) => $q->where('estado', 'Activo'))
                ->get();

            $hoy       = Carbon::parse($fecha);
            $countFijo = 0;
            $countExtr = 0;

            foreach ($empleados as $emp) {
                $il = $emp->informacionLaboral;

                $fechaInicio = Carbon::parse($il->fecha_inicio);
                $diasBase    = (int) min(365, $fechaInicio->diffInDays($hoy));

                if ($tipo === 'Fijos' || $tipo === 'Ambos') {
                    AguinaldoFijo::create([
                        'nombre_aguinaldo' => $nombre,
                        'departamento'     => $emp->departamento?->nombre ?? '',
                        'nombres'          => $emp->nombres,
                        'apellidos'        => $emp->apellidos,
                        'cuenta'           => $il->num_cuenta,
                        'fecha_inicio'     => $il->fecha_inicio,
                        'salario_base'     => $il->salario_base,
                        'dias_trabajados'  => $diasBase,
                        'anticipo'         => 0,
                        'total_aguinaldo'  => round(($il->salario_base / 365) * $diasBase, 2),
                        'fecha_generada'   => $fecha,
                        'estado'           => 'Activo',
                        'tipo_aguinaldo'   => $tipo,
                        'id_empleado'      => $emp->id,
                        'id_info_laboral'  => $il->id,
                        'id_departamento'  => $emp->id_departamento,
                    ]);
                    $countFijo++;
                }

                if ($tipo === 'Extras' || $tipo === 'Ambos') {
                    $anio        = $hoy->year;
                    $diasProm    = (int) round(
                        DetallePlanilla::where('id_empleado', $emp->id)
                            ->where('tipo_planilla', 'Extras')
                            ->whereYear('fecha_generada', $anio)
                            ->avg('dias_trabajados') ?? 15
                    );

                    $diario   = $il->salario_diario;
                    $subtotal = round($diario * $diasProm, 2);

                    AguinaldoExtra::create([
                        'nombre_aguinaldo' => $nombre,
                        'departamento'     => $emp->departamento?->nombre ?? '',
                        'nombres'          => $emp->nombres,
                        'apellidos'        => $emp->apellidos,
                        'cuenta'           => $il->num_cuenta,
                        'fecha_inicio'     => $il->fecha_inicio,
                        'salario_base'     => $il->salario_base,
                        'diario'           => $diario,
                        'antiguedad'       => 0,
                        'dias_promedio'    => $diasProm,
                        'subtotal'         => $subtotal,
                        'anticipos'        => 0,
                        'total_aguinaldo'  => $subtotal,
                        'fecha_generada'   => $fecha,
                        'estado'           => 'Activo',
                        'tipo_aguinaldo'   => $tipo,
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
            'dias_trabajados' => 'sometimes|integer|min:0|max:365',
            'anticipo'        => 'sometimes|numeric|min:0',
        ]);

        $dias  = $request->input('dias_trabajados', $registro->dias_trabajados);
        $antic = (float) $request->input('anticipo', $registro->anticipo);
        $total = max(0, round(($registro->salario_base / 365) * $dias - $antic, 2));

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
            'dias_promedio' => 'sometimes|integer|min:0',
            'antiguedad'    => 'sometimes|numeric|min:0',
            'anticipos'     => 'sometimes|numeric|min:0',
        ]);

        $dias      = $request->input('dias_promedio', $registro->dias_promedio);
        $antig     = (float) $request->input('antiguedad', $registro->antiguedad);
        $anticipos = (float) $request->input('anticipos', $registro->anticipos);
        $subtotal  = round($registro->diario * $dias + $antig, 2);
        $total     = max(0, round($subtotal - $anticipos, 2));

        $registro->update([
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
    public function destroy($nombre)
    {
        $fijos  = AguinaldoFijo::where('nombre_aguinaldo', $nombre)->where('estado', 'Activo');
        $extras = AguinaldoExtra::where('nombre_aguinaldo', $nombre)->where('estado', 'Activo');

        abort_if($fijos->count() + $extras->count() === 0, 404, 'Aguinaldo no encontrado o ya cerrado.');

        $fijos->delete();
        $extras->delete();

        return response()->json(['message' => 'Aguinaldo eliminado.']);
    }

    // ─── PDF export ───────────────────────────────────────────────
    public function exportPdf($nombre)
    {
        $fijos  = AguinaldoFijo::where('nombre_aguinaldo', $nombre)
            ->orderBy('departamento')->orderBy('apellidos')->get();
        $extras = AguinaldoExtra::where('nombre_aguinaldo', $nombre)
            ->orderBy('departamento')->orderBy('apellidos')->get();

        abort_if($fijos->isEmpty() && $extras->isEmpty(), 404, 'Aguinaldo no encontrado.');

        $meta            = ($fijos->first() ?? $extras->first());
        $totalesFijos    = $this->totalesFijos($fijos);
        $totalesExtras   = $this->totalesExtras($extras);

        $pdf = Pdf::loadView('aguinaldo.pdf', compact(
            'nombre', 'fijos', 'extras', 'meta', 'totalesFijos', 'totalesExtras'
        ))->setPaper('a4', 'landscape');

        return $pdf->download("aguinaldo_{$nombre}.pdf");
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
            'antiguedad'      => $extras->sum('antiguedad'),
            'anticipos'       => $extras->sum('anticipos'),
            'total_aguinaldo' => $extras->sum('total_aguinaldo'),
        ];
    }
}
