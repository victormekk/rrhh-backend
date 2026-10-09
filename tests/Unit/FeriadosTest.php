<?php

namespace Tests\Unit;

use App\Calculos\Feriados;
use Carbon\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class FeriadosTest extends TestCase
{
    private function fechasDe(int $anio, string $nombre): array
    {
        return array_values(array_map(
            fn ($f) => $f['fecha']->format('Y-m-d'),
            array_filter(Feriados::delAnio($anio), fn ($f) => $f['nombre'] === $nombre)
        ));
    }

    // Semana Santa cambia cada año: jueves, viernes y sábado antes del Domingo de Pascua.
    public static function semanasSantas(): array
    {
        return [
            '2024 (Pascua 31 mar)' => [2024, '2024-03-28', '2024-03-29', '2024-03-30'],
            '2025 (Pascua 20 abr)' => [2025, '2025-04-17', '2025-04-18', '2025-04-19'],
            '2026 (Pascua 5 abr)'  => [2026, '2026-04-02', '2026-04-03', '2026-04-04'],
            '2027 (Pascua 28 mar)' => [2027, '2027-03-25', '2027-03-26', '2027-03-27'],
            '2030 (Pascua 21 abr)' => [2030, '2030-04-18', '2030-04-19', '2030-04-20'],
        ];
    }

    #[DataProvider('semanasSantas')]
    public function test_semana_santa_se_calcula_desde_la_pascua(int $anio, string $jueves, string $viernes, string $sabado): void
    {
        $this->assertSame([$jueves], $this->fechasDe($anio, 'Jueves Santo'));
        $this->assertSame([$viernes], $this->fechasDe($anio, 'Viernes Santo'));
        $this->assertSame([$sabado], $this->fechasDe($anio, 'Sábado de Gloria'));
    }

    // Feriado Morazánico: miércoles, jueves y viernes desde el primer miércoles de octubre.
    public static function morazanicos(): array
    {
        return [
            '2024: 1 oct es martes'    => [2024, ['2024-10-02', '2024-10-03', '2024-10-04']],
            '2025: 1 oct es miércoles' => [2025, ['2025-10-01', '2025-10-02', '2025-10-03']],
            '2026: 1 oct es jueves'    => [2026, ['2026-10-07', '2026-10-08', '2026-10-09']],
        ];
    }

    #[DataProvider('morazanicos')]
    public function test_feriado_morazanico_son_tres_dias_desde_el_primer_miercoles_de_octubre(int $anio, array $esperado): void
    {
        $fechas = $this->fechasDe($anio, 'Feriado Morazánico');

        $this->assertSame($esperado, $fechas);
        $this->assertSame(Carbon::WEDNESDAY, Carbon::parse($fechas[0])->dayOfWeek);
    }

    public function test_todos_los_anios_tienen_los_feriados_fijos(): void
    {
        foreach (range(2020, 2035) as $anio) {
            $fechas = array_map(fn ($f) => $f['fecha']->format('m-d'), Feriados::delAnio($anio));

            $this->assertCount(11, $fechas, "Año $anio");
            foreach (['01-01', '04-14', '05-01', '09-15', '12-25'] as $fijo) {
                $this->assertContains($fijo, $fechas, "Año $anio, falta $fijo");
            }
        }
    }

    public function test_nombre_devuelve_el_feriado_o_null(): void
    {
        $this->assertSame('Navidad', Feriados::nombre(Carbon::parse('2026-12-25')));
        $this->assertSame('Día de la Independencia', Feriados::nombre(Carbon::parse('2026-09-15 15:30')));
        $this->assertNull(Feriados::nombre(Carbon::parse('2026-12-24')));
    }

    // Fecha de reintegro después de vacaciones: salta domingos y feriados.
    public static function reintegros(): array
    {
        return [
            'día normal'                         => ['2026-03-10', '2026-03-11'],
            'sábado → salta el domingo'          => ['2026-03-14', '2026-03-16'],
            'antes de Semana Santa y domingo'    => ['2026-04-01', '2026-04-06'],
            'antes de Navidad → sábado 26'       => ['2026-12-24', '2026-12-26'],
            'antes del Feriado Morazánico 2026'  => ['2026-10-06', '2026-10-10'],
        ];
    }

    #[DataProvider('reintegros')]
    public function test_siguiente_dia_habil(string $ultimoDiaVacaciones, string $esperado): void
    {
        $this->assertSame($esperado, Feriados::siguienteDiaHabil(Carbon::parse($ultimoDiaVacaciones))->format('Y-m-d'));
    }
}
