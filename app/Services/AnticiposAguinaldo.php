<?php

namespace App\Services;

use App\Calculos\CalculoAguinaldo;
use App\Models\AguinaldoExtra;
use App\Models\AguinaldoFijo;
use App\Models\IngresoDeduccion;
use App\Models\IngresoDeduccionAplicada;

// Anticipos de aguinaldo/catorceavo registrados en Ingresos/Deducciones: se suman a
// la columna "Anticipo" de la planilla especial del concepto elegido (Aguinaldo o
// Catorceavo), completos y una sola vez. Al cerrarla cuentan como pagados si el
// monto sigue en la celda; si se quitó a mano quedan pendientes para la próxima.
class AnticiposAguinaldo
{
    // Carga en el lote (abierto) los anticipos activos de su concepto que aún no
    // están en ningún lote. Devuelve cuántos se cargaron.
    public function cargar(string $nombre): int
    {
        $filas = $this->filas($nombre);
        if ($filas->isEmpty()) {
            return 0;
        }

        $registros = IngresoDeduccion::where('estado', 'Activo')
            ->where('aplica_en', $filas->first()->concepto)
            ->whereIn('id_empleado', $filas->pluck('id_empleado'))
            // Uno que ya está en otro lote abierto no se repite.
            ->doesntHave('aplicaciones')
            ->orderBy('id')
            ->get();

        foreach ($registros as $r) {
            $this->ajustar($filas->firstWhere('id_empleado', $r->id_empleado), (float) $r->monto);
            IngresoDeduccionAplicada::create([
                'id_ingreso_deduccion' => $r->id,
                'nombre_aguinaldo'     => $nombre,
                'columna'              => 'anticipo',
                'monto'                => $r->monto,
            ]);
        }

        return $registros->count();
    }

    // Recién registrado, editado o reanudado: si ya hay un lote abierto de su
    // concepto con ese empleado, se carga ahí mismo. Devuelve el nombre del lote.
    public function cargarRegistro(IngresoDeduccion $registro): ?string
    {
        if (!$registro->esAnticipo() || $registro->estado !== 'Activo') {
            return null;
        }

        $nombre = AguinaldoFijo::where('estado', 'Activo')->where('concepto', $registro->aplica_en)
                ->where('id_empleado', $registro->id_empleado)->orderBy('id')->value('nombre_aguinaldo')
            ?? AguinaldoExtra::where('estado', 'Activo')->where('concepto', $registro->aplica_en)
                ->where('id_empleado', $registro->id_empleado)->orderBy('id')->value('nombre_aguinaldo');

        if ($nombre === null) {
            return null;
        }
        $this->cargar($nombre);

        return $registro->aplicaciones()->where('nombre_aguinaldo', $nombre)->exists() ? $nombre : null;
    }

    // Lo saca del lote abierto donde esté pendiente (al pausarlo, cancelarlo, editarlo o eliminarlo).
    public function quitarRegistro(IngresoDeduccion $registro): void
    {
        $pendientes = $registro->aplicaciones()->whereNotNull('nombre_aguinaldo')->where('aplicada', false)->get();

        foreach ($pendientes as $a) {
            if ($fila = $this->fila($a->nombre_aguinaldo, $registro->id_empleado)) {
                $this->ajustar($fila, -(float) $a->monto);
            }
            $a->delete();
        }
    }

    // Al cerrar el lote: cuentan como pagados mientras el anticipo de la celda alcance.
    public function confirmarAlCerrar(string $nombre): void
    {
        $aplicaciones = IngresoDeduccionAplicada::with('ingresoDeduccion')
            ->where('nombre_aguinaldo', $nombre)->where('aplicada', false)
            ->orderBy('id')->get();

        foreach ($aplicaciones->groupBy(fn ($a) => $a->ingresoDeduccion->id_empleado) as $idEmpleado => $grupo) {
            $fila       = $this->fila($nombre, $idEmpleado);
            $disponible = $fila ? $this->anticipoDe($fila) : 0;

            foreach ($grupo as $a) {
                if ($disponible + 0.005 >= (float) $a->monto) {
                    $a->update(['aplicada' => true]);
                    $disponible -= (float) $a->monto;
                } else {
                    $a->delete();
                }
            }
        }

        $aplicaciones->pluck('ingresoDeduccion')->unique('id')->each->recalcularEstado();
    }

    // Al anular el lote: los anticipos vuelven a quedar pendientes.
    public function liberar(string $nombre): void
    {
        IngresoDeduccionAplicada::where('nombre_aguinaldo', $nombre)->where('aplicada', false)->delete();
    }

    private function filas(string $nombre)
    {
        return AguinaldoFijo::where('nombre_aguinaldo', $nombre)->where('estado', 'Activo')->lockForUpdate()->get()
            ->concat(AguinaldoExtra::where('nombre_aguinaldo', $nombre)->where('estado', 'Activo')->lockForUpdate()->get());
    }

    private function fila(string $nombre, int $idEmpleado): AguinaldoFijo|AguinaldoExtra|null
    {
        return AguinaldoFijo::where('nombre_aguinaldo', $nombre)->where('id_empleado', $idEmpleado)->lockForUpdate()->first()
            ?? AguinaldoExtra::where('nombre_aguinaldo', $nombre)->where('id_empleado', $idEmpleado)->lockForUpdate()->first();
    }

    private function anticipoDe(AguinaldoFijo|AguinaldoExtra $fila): float
    {
        return (float) ($fila instanceof AguinaldoFijo ? $fila->anticipo : $fila->anticipos);
    }

    // Suma (o resta) al anticipo de la fila y recalcula su total, igual que al editarla a mano.
    private function ajustar(AguinaldoFijo|AguinaldoExtra $fila, float $delta): void
    {
        $anticipo = max(0, round($this->anticipoDe($fila) + $delta, 2));

        if ($fila instanceof AguinaldoFijo) {
            $fila->update([
                'anticipo'        => $anticipo,
                'total_aguinaldo' => CalculoAguinaldo::totalFijo((float) $fila->salario_base, (int) $fila->dias_trabajados, $anticipo),
            ]);
            return;
        }

        [$subtotal, $total] = $fila->totalesCon($anticipo);
        $fila->update(['anticipos' => $anticipo, 'subtotal' => $subtotal, 'total_aguinaldo' => $total]);
    }
}
