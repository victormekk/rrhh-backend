<?php

namespace Tests\Unit;

use App\Calculos\CalculoVacaciones;
use Carbon\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CalculoVacacionesTest extends TestCase
{
    private function f(string $fecha): Carbon
    {
        return Carbon::parse($fecha);
    }

    // ── Días laborables (no cuentan domingos ni feriados) ─────────────────────

    public function test_una_semana_normal_son_seis_dias_porque_el_sabado_cuenta(): void
    {
        $r = CalculoVacaciones::diasLaborables($this->f('2026-03-09'), $this->f('2026-03-15')); // lunes a domingo

        $this->assertSame(6, $r['dias']);
        $this->assertSame([], $r['feriados']);
    }

    public function test_semana_santa_no_cuenta_y_avisa_cuales_feriados_cayeron(): void
    {
        $r = CalculoVacaciones::diasLaborables($this->f('2026-03-30'), $this->f('2026-04-05')); // lunes a domingo de Pascua

        $this->assertSame(3, $r['dias']); // lunes, martes y miércoles
        $this->assertSame([
            ['fecha' => '2026-04-02', 'nombre' => 'Jueves Santo'],
            ['fecha' => '2026-04-03', 'nombre' => 'Viernes Santo'],
            ['fecha' => '2026-04-04', 'nombre' => 'Sábado de Gloria'],
        ], $r['feriados']);
    }

    public function test_un_feriado_en_domingo_no_se_reporta_porque_el_domingo_ya_no_cuenta(): void
    {
        $r = CalculoVacaciones::diasLaborables($this->f('2024-09-15'), $this->f('2024-09-15')); // 15 de septiembre 2024 fue domingo

        $this->assertSame(0, $r['dias']);
        $this->assertSame([], $r['feriados']);
    }

    public function test_un_solo_dia_habil_cuenta_uno_y_un_rango_invertido_cero(): void
    {
        $this->assertSame(1, CalculoVacaciones::diasLaborables($this->f('2026-03-10'), $this->f('2026-03-10'))['dias']);
        $this->assertSame(0, CalculoVacaciones::diasLaborables($this->f('2026-03-10'), $this->f('2026-03-09'))['dias']);
    }

    public function test_las_horas_no_afectan_el_conteo(): void
    {
        $r = CalculoVacaciones::diasLaborables($this->f('2026-03-09 17:45'), $this->f('2026-03-14 08:00'));

        $this->assertSame(6, $r['dias']);
    }

    // ── Escala por antigüedad ─────────────────────────────────────────────────

    public static function escala(): array
    {
        return [
            'sin año cumplido' => [0, 0],
            'primer año'       => [1, 10],
            'segundo año'      => [2, 12],
            'tercer año'       => [3, 15],
            'cuarto año'       => [4, 20],
            'décimo año'       => [10, 20],
        ];
    }

    #[DataProvider('escala')]
    public function test_dias_por_cada_anio_laboral(int $anio, int|float $esperado): void
    {
        $this->assertEquals($esperado, (new CalculoVacaciones())->diasDelAnio($anio));
    }

    public function test_la_escala_se_puede_configurar(): void
    {
        $calculo = new CalculoVacaciones([1 => 12, 2 => 14, 3 => 16, 4 => 22]);

        $this->assertEquals(14, $calculo->diasDelAnio(2));
        $this->assertEquals(22, $calculo->diasDelAnio(7));
    }

    // ── Saldo ─────────────────────────────────────────────────────────────────

    public function test_menos_de_un_anio_no_tiene_dias_todavia(): void
    {
        $s = (new CalculoVacaciones())->saldo($this->f('2026-01-15'), $this->f('2026-10-08'), 0, 0);

        $this->assertSame(0, $s['anios_laborados']);
        $this->assertEquals(0, $s['dias_por_ley']);
        $this->assertEquals(0, $s['saldo']);
        $this->assertSame('2026-01-15', $s['periodo_inicio']);
        $this->assertSame('2027-01-14', $s['periodo_fin']);
    }

    public function test_dos_anios_sin_tomar_nada_acumula_diez_mas_doce(): void
    {
        $s = (new CalculoVacaciones())->saldo($this->f('2024-03-01'), $this->f('2026-10-08'), 0, 0);

        $this->assertSame(2, $s['anios_laborados']);
        $this->assertEquals(22, $s['dias_por_ley']);
        $this->assertEquals(12, $s['dias_anio_actual']);
        $this->assertEquals(10, $s['dias_previos']);
        $this->assertEquals(22, $s['saldo']);
        $this->assertSame('2026-03-01', $s['periodo_inicio']);
        $this->assertSame('2027-02-28', $s['periodo_fin']);
    }

    public function test_cinco_anios_acumula_toda_la_escala(): void
    {
        $s = (new CalculoVacaciones())->saldo($this->f('2021-01-04'), $this->f('2026-02-01'), 0, 0);

        $this->assertSame(5, $s['anios_laborados']);
        $this->assertEquals(10 + 12 + 15 + 20 + 20, $s['dias_por_ley']);
        $this->assertEquals(20, $s['dias_anio_actual']);
    }

    public function test_lo_tomado_antes_del_periodo_sale_de_los_dias_previos(): void
    {
        // 15 días tomados: 10 en años anteriores y 5 en el período vigente
        $s = (new CalculoVacaciones())->saldo($this->f('2024-03-01'), $this->f('2026-10-08'), 15, 5);

        $this->assertEquals(0, $s['dias_previos']); // 10 ganados antes − 10 tomados antes
        $this->assertEquals(7, $s['saldo']);        // 22 − 15
    }

    public function test_si_en_el_periodo_toma_mas_de_su_cuota_el_excedente_sale_de_los_previos(): void
    {
        // 15 días tomados, todos en el período vigente (cuota del período: 12)
        $s = (new CalculoVacaciones())->saldo($this->f('2024-03-01'), $this->f('2026-10-08'), 15, 15);

        $this->assertEquals(7, $s['dias_previos']); // 10 previos − 3 de excedente
        $this->assertEquals(7, $s['saldo']);
    }

    public function test_el_saldo_nunca_es_negativo(): void
    {
        $s = (new CalculoVacaciones())->saldo($this->f('2024-03-01'), $this->f('2026-10-08'), 30, 20);

        $this->assertEquals(0, $s['saldo']);
        $this->assertEquals(0, $s['dias_previos']);
    }

    // Con un 29 de febrero de por medio, el año laboral se cumple en el aniversario,
    // no un día antes (antes se contaban bloques de 365 días).
    public static function aniversariosConBisiesto(): array
    {
        return [
            'víspera del 3er aniversario (pasa por 29 feb 2024)' => ['2023-01-10', '2026-01-09', 2, 22, '2025-01-10'],
            'día del 3er aniversario'                            => ['2023-01-10', '2026-01-10', 3, 37, '2026-01-10'],
            'víspera del 4to aniversario'                        => ['2020-03-01', '2024-02-29', 3, 37, '2023-03-01'],
            'día del 4to aniversario'                            => ['2020-03-01', '2024-03-01', 4, 57, '2024-03-01'],
        ];
    }

    #[DataProvider('aniversariosConBisiesto')]
    public function test_el_anio_se_cumple_en_el_aniversario_aunque_haya_bisiesto(string $inicio, string $hoy, int $anios, int $dias, string $periodo): void
    {
        $s = (new CalculoVacaciones())->saldo($this->f($inicio), $this->f($hoy), 0, 0);

        $this->assertSame($anios, $s['anios_laborados']);
        $this->assertEquals($dias, $s['dias_por_ley']);
        $this->assertSame($periodo, $s['periodo_inicio']);
    }

    public function test_los_anios_y_el_periodo_siempre_coinciden(): void
    {
        $calculo = new CalculoVacaciones();
        $inicio  = $this->f('2021-06-15');

        // Todos los días de 2021 a 2027: el período empieza exactamente "años" después del inicio
        for ($hoy = $inicio->copy(); $hoy->lte($this->f('2027-12-31')); $hoy->addDay()) {
            $s = $calculo->saldo($inicio, $hoy, 0, 0);
            $this->assertSame(
                $inicio->copy()->addYears($s['anios_laborados'])->format('Y-m-d'),
                $s['periodo_inicio'],
                "Hoy {$hoy->format('Y-m-d')}"
            );
        }
    }

    // Ya no se puede registrar un 29 de febrero como inicio, pero si existiera uno,
    // su aniversario cuenta el 28 de febrero en los años no bisiestos.
    public function test_quien_empezo_un_29_de_febrero_cumple_el_28_en_anios_no_bisiestos(): void
    {
        $calculo = new CalculoVacaciones();
        $inicio  = $this->f('2024-02-29');

        $this->assertSame(0, $calculo->saldo($inicio, $this->f('2025-02-27'), 0, 0)['anios_laborados']);

        $s = $calculo->saldo($inicio, $this->f('2025-02-28'), 0, 0);
        $this->assertSame(1, $s['anios_laborados']);
        $this->assertSame('2025-02-28', $s['periodo_inicio']);
        $this->assertSame('2026-02-27', $s['periodo_fin']);

        $s = $calculo->saldo($inicio, $this->f('2028-02-28'), 0, 0); // año bisiesto: todavía no cumple
        $this->assertSame(3, $s['anios_laborados']);
        $this->assertSame('2028-02-28', $s['periodo_fin']);

        $s = $calculo->saldo($inicio, $this->f('2028-02-29'), 0, 0); // año bisiesto: cumple el 29
        $this->assertSame(4, $s['anios_laborados']);
        $this->assertSame('2028-02-29', $s['periodo_inicio']);
    }

    public function test_el_dia_del_aniversario_empieza_el_nuevo_periodo(): void
    {
        $calculo = new CalculoVacaciones();

        $this->assertSame('2025-03-01', $calculo->saldo($this->f('2024-03-01'), $this->f('2026-02-28'), 0, 0)['periodo_inicio']);
        $this->assertSame('2026-03-01', $calculo->saldo($this->f('2024-03-01'), $this->f('2026-03-01'), 0, 0)['periodo_inicio']);
    }
}
