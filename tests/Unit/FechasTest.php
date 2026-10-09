<?php

namespace Tests\Unit;

use App\Calculos\Fechas;
use App\Rules\NoEs29Febrero;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

class FechasTest extends TestCase
{
    public function test_aniversario_normal_es_el_mismo_dia_y_mes(): void
    {
        $this->assertSame('2026-10-08', Fechas::aniversarioEn(Carbon::parse('1990-10-08'), 2026)->format('Y-m-d'));
        $this->assertSame('2027-12-31 00:00', Fechas::aniversarioEn(Carbon::parse('2000-12-31 18:30'), 2027)->format('Y-m-d H:i')); // sin hora
    }

    // Nacidos o contratados un 29 de febrero: se toma el 28 en años no bisiestos.
    public function test_29_de_febrero_se_toma_como_28_en_anios_no_bisiestos(): void
    {
        $nacio = Carbon::parse('2000-02-29');

        $this->assertSame('2025-02-28', Fechas::aniversarioEn($nacio, 2025)->format('Y-m-d'));
        $this->assertSame('2026-02-28', Fechas::aniversarioEn($nacio, 2026)->format('Y-m-d'));
        $this->assertSame('2028-02-29', Fechas::aniversarioEn($nacio, 2028)->format('Y-m-d'));
        $this->assertSame('2100-02-28', Fechas::aniversarioEn($nacio, 2100)->format('Y-m-d')); // 2100 no es bisiesto
    }

    public function test_es_29_de_febrero(): void
    {
        $this->assertTrue(Fechas::es29DeFebrero('2024-02-29'));
        $this->assertTrue(Fechas::es29DeFebrero(Carbon::parse('2028-02-29')));
        $this->assertFalse(Fechas::es29DeFebrero('2024-02-28'));
        $this->assertFalse(Fechas::es29DeFebrero('2024-03-01'));
        $this->assertFalse(Fechas::es29DeFebrero(null));
    }

    public function test_la_regla_rechaza_29_de_febrero_como_fecha_de_inicio(): void
    {
        $falla = function (string $fecha): ?string {
            $mensaje = null;
            (new NoEs29Febrero)->validate('fecha_inicio', $fecha, function ($m) use (&$mensaje) { $mensaje = $m; });
            return $mensaje;
        };

        $this->assertStringContainsString('29 de febrero', $falla('2024-02-29'));
        $this->assertNull($falla('2024-02-28'));
        $this->assertNull($falla('2024-03-01'));
        $this->assertNull($falla('no-es-fecha')); // la regla "date" se encarga de eso
    }
}
