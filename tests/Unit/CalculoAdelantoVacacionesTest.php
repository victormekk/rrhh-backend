<?php

namespace Tests\Unit;

use App\Calculos\CalculoVacaciones;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

// Adelanto de vacaciones: se pueden tomar por adelantado los días del próximo período.
class CalculoAdelantoVacacionesTest extends TestCase
{
    private function saldo(string $inicio, string $hoy, float $total, float $periodo = 0): array
    {
        return (new CalculoVacaciones())->saldo(Carbon::parse($inicio), Carbon::parse($hoy), $total, $periodo);
    }

    public function test_sin_cumplir_el_primer_anio_puede_adelantar_los_10_dias_del_primer_anio(): void
    {
        $s = $this->saldo('2026-08-01', '2026-11-04', 0);

        $this->assertSame(0, $s['saldo']);
        $this->assertSame(10.0, $s['dias_proximo_periodo']);
        $this->assertSame('2027-08-01', $s['proximo_periodo_inicio']);
        $this->assertSame(10.0, $s['adelanto_disponible']);
        $this->assertSame(10.0, $s['maximo_solicitable']);
    }

    public function test_con_saldo_el_maximo_es_solo_el_saldo_del_periodo(): void
    {
        // Cumplió 1 año (10 días), tomó 7: le quedan 3. Mientras tenga saldo no se adelanta.
        $s = $this->saldo('2025-08-01', '2026-11-04', 7, 7);

        $this->assertEquals(3, $s['saldo']);
        $this->assertEquals(3, $s['maximo_solicitable']);
    }

    public function test_con_el_saldo_en_cero_el_maximo_es_lo_que_se_puede_adelantar(): void
    {
        // Cumplió 1 año (10 días) y los tomó todos: puede adelantar los 12 del segundo año.
        $s = $this->saldo('2025-08-01', '2026-11-04', 10, 10);

        $this->assertEquals(0, $s['saldo']);
        $this->assertEquals(12, $s['maximo_solicitable']);
    }

    public function test_lo_adelantado_se_muestra_y_reduce_lo_que_aun_se_puede_adelantar(): void
    {
        // Primer año sin cumplir y ya adelantó 6 de los 10.
        $s = $this->saldo('2026-08-01', '2026-11-04', 6, 6);

        $this->assertSame(0, $s['saldo']);
        $this->assertEquals(6, $s['dias_adelantados']);
        $this->assertEquals(4, $s['adelanto_disponible']);
        $this->assertEquals(4, $s['maximo_solicitable']);
    }

    public function test_al_cumplir_el_aniversario_lo_adelantado_deja_el_saldo_en_cero(): void
    {
        // Adelantó los 10 días del primer año; al cumplirlo, el saldo queda en cero.
        $antes = $this->saldo('2026-08-01', '2027-07-31', 10, 10);
        $this->assertEquals(10, $antes['dias_adelantados']);
        $this->assertEquals(0, $antes['adelanto_disponible']);

        $despues = $this->saldo('2026-08-01', '2027-08-01', 10, 0);
        $this->assertEquals(0, $despues['saldo']);
        $this->assertEquals(0, $despues['dias_adelantados']);
        // Y ya puede adelantar los 12 del segundo año.
        $this->assertEquals(12, $despues['adelanto_disponible']);
    }

    public function test_si_adelanto_una_parte_al_cumplir_le_quedan_los_dias_restantes(): void
    {
        $s = $this->saldo('2026-08-01', '2027-08-01', 4, 0);

        $this->assertEquals(6, $s['saldo']);
        $this->assertEquals(0, $s['dias_adelantados']);
    }
}
