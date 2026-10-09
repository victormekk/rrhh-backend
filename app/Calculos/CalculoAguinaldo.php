<?php

namespace App\Calculos;

use Carbon\Carbon;

// Cálculos de las planillas especiales (aguinaldo y catorceavo). Sin base de datos.
// Reglas iguales al cálculo manual en Excel; se prueban contra las planillas reales en
// tests/Unit/CalculoAguinaldoTest.php.
class CalculoAguinaldo
{
    // ── Fijos ─────────────────────────────────────────────────────────────────

    // Días trabajados en base 360 (12 meses de 30 días): días calendario desde la fecha
    // de inicio hasta el corte, con máximo 360. Excel: =IF((corte-inicio)<360, corte-inicio, 360)
    public static function diasFijo(Carbon $inicio, Carbon $corte): int
    {
        // max(0): desde Carbon 3 diffInDays es negativo si el empleado inició
        // después del corte; en ese caso no acumula días.
        return (int) max(0, min(360, $inicio->diffInDays($corte, false)));
    }

    // Excel: =(salario / 360) * días − anticipo. Nunca negativo.
    public static function totalFijo(float $salario, int $dias, float $anticipo = 0): float
    {
        return max(0, round(($salario / 360) * $dias - $anticipo, 2));
    }

    // ── Extras ────────────────────────────────────────────────────────────────

    // Antigüedad en "días de 30": 30 si al corte cumple 360 días o más; si no,
    // proporcional (días al corte / 360 * 30). Días calendario, como en el Excel.
    public static function antiguedadExtra(Carbon $inicio, Carbon $corte): float
    {
        $dias = max(0, $inicio->diffInDays($corte, false));

        // Sin redondear: el total usa el valor exacto, como el Excel (la columna lo guarda con 4 decimales).
        return $dias >= 360 ? 30.0 : $dias / 360 * 30;
    }

    // Subtotal = diario × antigüedad. Total = días prom. / 30 × subtotal − anticipos.
    // Sin promedio (trabaja todos los días): total = subtotal − anticipos.
    // Devuelve [subtotal, total], ambos redondeados a 2 decimales.
    public static function totalExtra(float $diario, float $antiguedad, ?int $diasProm, float $anticipos): array
    {
        $subtotal = $diario * $antiguedad;
        $factor   = $diasProm === null ? 1 : $diasProm / 30;

        return [round($subtotal, 2), max(0, round($factor * $subtotal - $anticipos, 2))];
    }

    // Promedio mensual de días de un extra: suma de días por quincena (máx. 15 cada una)
    // entre los meses con planilla. Excel: =SUMA(quincenas) / meses
    public static function promedioMensual(array $diasPorQuincena, float $meses): float
    {
        if ($meses == 0) {
            return 0;
        }

        $total = array_sum(array_map(fn ($d) => min(15, $d), $diasPorQuincena));

        return round($total / $meses, 3);
    }

    // Días promediados: se cortan los decimales (17.9 → 17), máximo 30.
    public static function diasPromediados(?float $promedio): int
    {
        return (int) min(30, floor(round((float) $promedio, 6)));
    }
}
