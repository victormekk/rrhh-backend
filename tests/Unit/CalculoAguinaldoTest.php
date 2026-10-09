<?php

namespace Tests\Unit;

use App\Calculos\CalculoAguinaldo;
use Carbon\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

// Aguinaldo y catorceavo comparados contra las planillas que se calcularon a mano en Excel.
// Los casos están en tests/Fixtures (sin nombres de empleados: solo fechas y montos).
class CalculoAguinaldoTest extends TestCase
{
    private static function fixture(string $archivo): array
    {
        return json_decode(file_get_contents(__DIR__ . '/../Fixtures/' . $archivo), true);
    }

    private function f(string $fecha): Carbon
    {
        return Carbon::parse($fecha);
    }

    // ── Casos reales: Aguinaldo Fijos 2025 (117 empleados, corte 31/12/2025) ────

    public static function aguinaldoFijos2025(): iterable
    {
        $datos = self::fixture('aguinaldo_fijos_2025.json');
        foreach ($datos['filas'] as $f) {
            yield "fila {$f['fila']} (inicio {$f['inicio']})" => [$datos['corte'], $f];
        }
    }

    #[DataProvider('aguinaldoFijos2025')]
    public function test_aguinaldo_fijos_2025_igual_al_excel(string $corte, array $fila): void
    {
        $dias = CalculoAguinaldo::diasFijo($this->f($fila['inicio']), $this->f($corte));

        $this->assertSame($fila['dias'], $dias, 'Días año');
        $this->assertEqualsWithDelta(
            $fila['total'],
            CalculoAguinaldo::totalFijo((float) $fila['salario'], $dias, (float) $fila['anticipo']),
            0.005,
            'Aguinaldo a pagar'
        );
    }

    // ── Casos reales: Catorceavo Extras 2026 (50 empleados, corte 30/06/2026) ────

    public static function catorceavoExtras2026(): iterable
    {
        $datos = self::fixture('catorceavo_extras_2026.json');
        foreach ($datos['filas'] as $f) {
            yield "fila {$f['fila']} (inicio {$f['inicio']})" => [$datos['corte'], $f];
        }
    }

    #[DataProvider('catorceavoExtras2026')]
    public function test_catorceavo_extras_2026_igual_al_excel(string $corte, array $fila): void
    {
        $antiguedad = CalculoAguinaldo::antiguedadExtra($this->f($fila['inicio']), $this->f($corte));
        [$subtotal, $total] = CalculoAguinaldo::totalExtra((float) $fila['diario'], $antiguedad, $fila['dias_prom'], (float) $fila['anticipos']);

        $this->assertEqualsWithDelta($fila['antiguedad'], $antiguedad, 0.0001, 'Antigüedad');
        $this->assertEqualsWithDelta($fila['subtotal'], $subtotal, 0.005, 'Subtotal');
        $this->assertEqualsWithDelta($fila['total'], $total, 0.005, 'Total a pagar');
    }

    // ── Casos reales: días promedio de los extras (quincenas → días del catorceavo) ──

    public static function promedioDias2026(): iterable
    {
        $datos = self::fixture('promedio_dias_2026.json');
        foreach ($datos['filas'] as $f) {
            yield "fila {$f['fila']}" => [$datos['meses'], $f];
        }
    }

    #[DataProvider('promedioDias2026')]
    public function test_dias_promedio_igual_al_excel(int $meses, array $fila): void
    {
        $promedio = CalculoAguinaldo::promedioMensual($fila['quincenas'], $meses);

        $this->assertEqualsWithDelta($fila['promedio_excel'], $promedio, 0.001, 'Promedio');
        $this->assertSame($fila['dias_prom_catorceavo'], CalculoAguinaldo::diasPromediados($promedio), 'Días promedio');
    }

    // ── Reglas sueltas ────────────────────────────────────────────────────────

    public function test_fijo_con_mas_de_un_anio_cobra_salario_completo(): void
    {
        $this->assertSame(360, CalculoAguinaldo::diasFijo($this->f('2010-03-15'), $this->f('2025-12-31')));
        $this->assertEquals(23400.0, CalculoAguinaldo::totalFijo(23400, 360));
    }

    public function test_fijo_que_entro_despues_del_corte_no_acumula(): void
    {
        $this->assertSame(0, CalculoAguinaldo::diasFijo($this->f('2026-01-05'), $this->f('2025-12-31')));
        $this->assertEquals(0.0, CalculoAguinaldo::totalFijo(15000, 0));
    }

    public function test_el_anticipo_se_descuenta_y_el_total_nunca_es_negativo(): void
    {
        $this->assertEquals(7625.2, CalculoAguinaldo::totalFijo(15250.2, 360, 7625));
        $this->assertEquals(0.0, CalculoAguinaldo::totalFijo(15750, 360, 15750));
        $this->assertEquals(0.0, CalculoAguinaldo::totalFijo(15750, 100, 99999));
    }

    public function test_antiguedad_de_extras(): void
    {
        $corte = $this->f('2026-06-30');

        $this->assertEquals(30.0, CalculoAguinaldo::antiguedadExtra($this->f('2025-07-05'), $corte)); // 360 días
        $this->assertEqualsWithDelta(29.9167, CalculoAguinaldo::antiguedadExtra($this->f('2025-07-06'), $corte), 0.0001); // 359 días
        $this->assertEquals(15.0, CalculoAguinaldo::antiguedadExtra($this->f('2026-01-01'), $corte)); // 180 días
        $this->assertEquals(0.0, CalculoAguinaldo::antiguedadExtra($this->f('2026-08-01'), $corte)); // entró después del corte
    }

    public function test_extra_que_trabaja_todos_los_dias_no_lleva_promedio(): void
    {
        [$subtotal, $total] = CalculoAguinaldo::totalExtra(543.92, 30, null, 0);

        $this->assertEquals(16317.6, $subtotal);
        $this->assertEquals(16317.6, $total);
    }

    public function test_extra_con_promedio_y_anticipos(): void
    {
        [$subtotal, $total] = CalculoAguinaldo::totalExtra(543.92, 30, 18, 1500);

        $this->assertEquals(16317.6, $subtotal);
        $this->assertEquals(8290.56, $total); // 18/30 × 16,317.60 − 1,500
        $this->assertEquals(0.0, CalculoAguinaldo::totalExtra(543.92, 30, 18, 99999)[1]);
    }

    public function test_dias_promediados_cortan_decimales_con_tope_de_30(): void
    {
        $this->assertSame(14, CalculoAguinaldo::diasPromediados(14.909));
        $this->assertSame(18, CalculoAguinaldo::diasPromediados(17.9999999)); // ruido de decimales
        $this->assertSame(30, CalculoAguinaldo::diasPromediados(35.2));
        $this->assertSame(0, CalculoAguinaldo::diasPromediados(null));
    }

    public function test_promedio_tope_de_15_dias_por_quincena_y_sin_meses_es_cero(): void
    {
        $this->assertEquals(4.091, CalculoAguinaldo::promedioMensual([15, 15, 20], 11)); // (15+15+15)/11: el 20 cuenta como 15
        $this->assertEquals(0, CalculoAguinaldo::promedioMensual([15, 15], 0));
    }
}
