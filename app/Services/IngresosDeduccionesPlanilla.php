<?php

namespace App\Services;

use App\Models\CabeceraPlanilla;
use App\Models\DetallePlanilla;
use App\Models\IngresoDeduccion;
use App\Models\IngresoDeduccionAplicada;
use Illuminate\Support\Collection;

// Lleva los ingresos/deducciones recurrentes (préstamos, adelantos, bonos fijos)
// a las planillas: los carga en las celdas al crearla y, al cerrarla, cuenta como
// pagada cada cuota que siguió en la celda.
class IngresosDeduccionesPlanilla
{
    // Columnas que suman en la deducción neta (igual que PlanillaController::updateDetalle).
    private const DEDUCCIONES = [
        'ihss', 'retencion_ahorro', 'crefisa', 'isr', 'transporte',
        'radios', 'uniforme', 'garden', 'i_vecinal', 'otras_deducciones',
    ];

    // Columnas ocultas en las planillas de Extras: lo que caiga ahí se pasa a "Otras deducciones".
    private const OCULTAS_EN_EXTRAS = ['crefisa', 'isr', 'ihss'];

    // 1 = días 1 al 15, 2 = del 16 a fin de mes.
    public static function quincenaDe($fecha): int
    {
        return (int) date('j', strtotime((string) $fecha)) <= 15 ? 1 : 2;
    }

    // Carga en la planilla (abierta) los registros activos que le tocan y que aún
    // no están en ella. Devuelve cuántos se cargaron. Se puede limitar a un registro
    // (recién creado, editado o reanudado) o a un empleado (recién agregado).
    public function cargar(CabeceraPlanilla $planilla, ?int $soloRegistro = null, ?int $soloEmpleado = null): int
    {
        $detalles = DetallePlanilla::where('id_cabecera_planilla', $planilla->id)
            ->when($soloEmpleado, fn ($q) => $q->where('id_empleado', $soloEmpleado))
            ->lockForUpdate()->get()->keyBy('id_empleado');
        if ($detalles->isEmpty()) {
            return 0;
        }

        $fecha    = $planilla->fecha_generada->toDateString();
        $quincena = self::quincenaDe($fecha);

        $yaCargados = IngresoDeduccionAplicada::where('id_cabecera_planilla', $planilla->id)
            ->pluck('id_ingreso_deduccion')->flip();

        $registros = IngresoDeduccion::withCount('aplicaciones')
            ->where('estado', 'Activo')
            ->where('aplica_en', 'Planilla') // los anticipos de aguinaldo/catorceavo van a su planilla especial
            ->whereIn('id_empleado', $detalles->keys())
            ->when($soloRegistro, fn ($q) => $q->where('id', $soloRegistro))
            ->whereDate('fecha_inicio', '<=', $fecha)
            ->where(fn ($q) => $q->where('frecuencia', 'Quincenal')->orWhere('quincena_mes', $quincena))
            ->orderBy('id')
            ->get()
            // Cuotas ya puestas en otra planilla (abierta o cerrada) también cuentan,
            // para no cobrar la última dos veces si hay dos planillas abiertas.
            ->filter(fn ($r) => !$yaCargados->has($r->id)
                && ($r->total_cuotas === null || $r->aplicaciones_count < $r->total_cuotas));

        foreach ($registros->groupBy('id_empleado') as $idEmpleado => $deEmpleado) {
            $detalle = $detalles[$idEmpleado];
            foreach ($deEmpleado as $r) {
                $columna = $r->columna;
                if ($planilla->tipo_planilla === 'Extras' && in_array($columna, self::OCULTAS_EN_EXTRAS)) {
                    $columna = 'otras_deducciones';
                }

                $detalle->$columna = round((float) $detalle->$columna + (float) $r->monto, 2);
                if ($campoDesc = IngresoDeduccion::DESCRIPCION_DE[$columna] ?? null) {
                    $detalle->$campoDesc = collect([$detalle->$campoDesc, self::item($r->concepto, $r->monto)])
                        ->filter()->implode(', ');
                }

                IngresoDeduccionAplicada::create([
                    'id_ingreso_deduccion' => $r->id,
                    'id_cabecera_planilla' => $planilla->id,
                    'id_detalle_planilla'  => $detalle->id,
                    'columna'              => $columna,
                    'monto'                => $r->monto,
                ]);
            }
            $this->recalcularTotales($detalle);
            $detalle->save();
        }

        return $registros->count();
    }

    // Al cerrar: marca como pagada cada cuota cuyo monto sigue en la celda. Si se
    // quitó a mano (ej. no se le cobró esa quincena), esa cuota no cuenta y el
    // préstamo se corre una quincena más.
    public function confirmarAlCerrar(CabeceraPlanilla $planilla): void
    {
        $aplicaciones = IngresoDeduccionAplicada::with(['detalle', 'ingresoDeduccion'])
            ->where('id_cabecera_planilla', $planilla->id)
            ->orderBy('id')
            ->get();

        foreach ($aplicaciones->groupBy(fn ($a) => "{$a->id_detalle_planilla}|{$a->columna}") as $grupo) {
            $detalle = $grupo->first()->detalle;
            $columna = $grupo->first()->columna;
            $campoDesc = IngresoDeduccion::DESCRIPCION_DE[$columna] ?? null;

            if ($campoDesc) {
                // Con descripción: cuenta si su renglón "Concepto: monto" sigue anotado.
                $renglones = collect(explode(', ', (string) $detalle->$campoDesc))->filter()->values();
                foreach ($grupo as $a) {
                    $pos = $renglones->search(self::item($a->ingresoDeduccion->concepto, $a->monto));
                    $this->marcar($a, $pos !== false);
                    if ($pos !== false) {
                        $renglones->forget($pos);
                    }
                }
            } else {
                // Sin descripción: cuentan mientras el monto de la celda alcance.
                $disponible = (float) $detalle->$columna;
                foreach ($grupo as $a) {
                    $alcanza = $disponible + 0.005 >= (float) $a->monto;
                    $this->marcar($a, $alcanza);
                    if ($alcanza) {
                        $disponible -= (float) $a->monto;
                    }
                }
            }
        }

        $aplicaciones->pluck('ingresoDeduccion')->unique('id')->each->recalcularEstado();
    }

    // Recién creado, editado o reanudado: se carga en las planillas abiertas donde
    // está su empleado. Devuelve los nombres de las planillas.
    public function cargarRegistro(IngresoDeduccion $registro): array
    {
        if ($registro->esAnticipo() || $registro->estado !== 'Activo') {
            return [];
        }

        return CabeceraPlanilla::where('estado', 'Activo')
            ->whereHas('detalles', fn ($q) => $q->where('id_empleado', $registro->id_empleado))
            ->orderBy('fecha_generada')->orderBy('id')
            ->get()
            ->filter(fn ($p) => $this->cargar($p, soloRegistro: $registro->id) > 0)
            ->pluck('nombre_planilla')->values()->all();
    }

    // Al pausarlo, cancelarlo, editarlo o eliminarlo: se saca de las planillas abiertas
    // donde está pendiente. Solo se resta lo que sigue en la celda (su renglón
    // "Concepto: monto" o, sin descripción, si el monto alcanza): si ya se había quitado
    // a mano, no se toca lo demás de la celda.
    public function quitarRegistro(IngresoDeduccion $registro): void
    {
        $pendientes = $registro->aplicaciones()->with('planilla:id,estado')
            ->whereNotNull('id_cabecera_planilla')->where('aplicada', false)->get();

        foreach ($pendientes as $a) {
            $detalle = $a->planilla?->estado === 'Activo'
                ? DetallePlanilla::lockForUpdate()->find($a->id_detalle_planilla)
                : null;

            if ($detalle) {
                $columna   = $a->columna;
                $campoDesc = IngresoDeduccion::DESCRIPCION_DE[$columna] ?? null;
                $restar    = false;

                if ($campoDesc) {
                    $renglones = collect(explode(', ', (string) $detalle->$campoDesc))->filter()->values();
                    $pos       = $renglones->search(self::item($registro->concepto, $a->monto));
                    if ($pos !== false) {
                        $renglones->forget($pos);
                        $detalle->$campoDesc = $renglones->implode(', ') ?: null;
                        $restar = true;
                    }
                } else {
                    $restar = (float) $detalle->$columna + 0.005 >= (float) $a->monto;
                }

                if ($restar) {
                    $detalle->$columna = max(0, round((float) $detalle->$columna - (float) $a->monto, 2));
                    $this->recalcularTotales($detalle);
                    $detalle->save();
                }
            }
            $a->delete();
        }
    }

    // Ids de los registros que tiene una planilla, para recalcular su estado después de borrarla.
    public function registrosDe(CabeceraPlanilla $planilla): Collection
    {
        return IngresoDeduccionAplicada::where('id_cabecera_planilla', $planilla->id)
            ->pluck('id_ingreso_deduccion')->unique();
    }

    public function recalcularEstados(Collection $ids): void
    {
        IngresoDeduccion::whereIn('id', $ids)->get()->each->recalcularEstado();
    }

    private function marcar(IngresoDeduccionAplicada $a, bool $pagada): void
    {
        $pagada ? $a->update(['aplicada' => true]) : $a->delete();
    }

    private function recalcularTotales(DetallePlanilla $d): void
    {
        $d->deduccion_neta = round(collect(self::DEDUCCIONES)->sum(fn ($c) => (float) $d->$c), 2);
        $d->salario_neto   = round((float) $d->salario_base + (float) $d->otros_ingresos
            + (float) $d->monto_horas_extras - $d->deduccion_neta, 2);
    }

    // Mismo formato que usa la pantalla de la planilla: "Concepto: 500" o "Concepto: 250.5".
    private static function item(string $concepto, $monto): string
    {
        return $concepto . ': ' . (string) (float) $monto;
    }
}
