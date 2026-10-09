<?php

namespace App\Calculos;

use Carbon\Carbon;

// Cálculo de vacaciones por antigüedad. Sin base de datos: el controlador le pasa
// la fecha de inicio, los días ya tomados y la escala configurada.
// Se prueba en tests/Unit/CalculoVacacionesTest.php.
class CalculoVacaciones
{
    public const ESCALA_POR_DEFECTO = [1 => 10.0, 2 => 12.0, 3 => 15.0, 4 => 20.0];

    // $escala: días por año laboral [1 => primer año, 2 => segundo, 3 => tercero, 4 => cuarto en adelante]
    public function __construct(private array $escala = self::ESCALA_POR_DEFECTO)
    {
    }

    // Días laborables entre dos fechas: no cuentan domingos ni feriados
    // nacionales. Devuelve también el detalle de los feriados que cayeron
    // entre semana (lunes-sábado) dentro del rango, para poder avisarle
    // al usuario cuáles fechas no se le están contando.
    public static function diasLaborables(Carbon $inicio, Carbon $fin): array
    {
        $dias     = 0;
        $feriados = [];
        $current  = $inicio->copy()->startOfDay();
        $fin      = $fin->copy()->startOfDay();

        while ($current->lte($fin)) {
            $nombreFeriado = Feriados::nombre($current);
            if ($current->dayOfWeek !== Carbon::SUNDAY) {
                if ($nombreFeriado) {
                    $feriados[] = ['fecha' => $current->format('Y-m-d'), 'nombre' => $nombreFeriado];
                } else {
                    $dias++;
                }
            }
            $current->addDay();
        }

        return ['dias' => $dias, 'feriados' => $feriados];
    }

    // Años laborales completos a la fecha, por aniversario de calendario: el año se
    // cumple el mismo día y mes de la fecha de inicio. (Antes se contaban bloques de
    // 365 días y, con un 29 de febrero de por medio, el año se cumplía un día antes.)
    // Se calcula desde el inicio del período vigente para que años y período siempre coincidan.
    public function aniosLaborados(Carbon $inicio, Carbon $hoy): int
    {
        [$periodoInicio] = $this->periodo($inicio, $hoy);

        return max(0, $periodoInicio->year - $inicio->year);
    }

    // Días que corresponden por el año laboral número $anio (1 = primer año).
    public function diasDelAnio(int $anio): int|float
    {
        return match (true) {
            $anio >= 4  => $this->escala[4],
            $anio === 3 => $this->escala[3],
            $anio === 2 => $this->escala[2],
            $anio >= 1  => $this->escala[1],
            default     => 0,
        };
    }

    // Período vacacional vigente: del último aniversario al día antes del siguiente.
    // Un inicio el 29 de febrero cumple el 28 de febrero en años no bisiestos.
    public function periodo(Carbon $inicio, Carbon $hoy): array
    {
        $hoy = $hoy->copy()->startOfDay();
        $anio = $hoy->year;
        $aniversario = Fechas::aniversarioEn($inicio, $anio);
        if ($aniversario->isAfter($hoy)) {
            $anio--;
            $aniversario = Fechas::aniversarioEn($inicio, $anio);
        }

        return [$aniversario, Fechas::aniversarioEn($inicio, $anio + 1)->subDay()];
    }

    // Saldo de vacaciones. $tomadosTotal: todos los días tomados; $tomadosPeriodo:
    // los tomados con fecha de inicio dentro del período vigente.
    public function saldo(Carbon $inicio, Carbon $hoy, float $tomadosTotal, float $tomadosPeriodo): array
    {
        $inicio = $inicio->copy()->startOfDay();
        $hoy    = $hoy->copy()->startOfDay();
        $anios  = $this->aniosLaborados($inicio, $hoy);

        // Días ganados por cada año laboral completado (acumulados)
        $diasAcumulados = 0;
        for ($i = 1; $i <= $anios; $i++) {
            $diasAcumulados += $this->diasDelAnio($i);
        }

        // Días del año aniversario actual
        $diasAnioActual = $this->diasDelAnio($anios);

        [$periodoInicio, $periodoFin] = $this->periodo($inicio, $hoy);

        // Días previos = lo ganado antes del período actual, menos lo tomado antes del período actual
        // y menos el excedente de lo tomado en el período actual sobre la cuota del período actual
        // (lo tomado se descuenta primero de la cuota del período actual; si se excede, el sobrante
        // sale de los días previos).
        $diasGanadosAnteriores = $diasAcumulados - $diasAnioActual;
        $diasTomadosAnteriores = $tomadosTotal - $tomadosPeriodo;
        $excedentePeriodo      = max(0, $tomadosPeriodo - $diasAnioActual);
        $diasPrevios           = max(0, $diasGanadosAnteriores - $diasTomadosAnteriores - $excedentePeriodo);

        return [
            'anios_laborados'      => $anios,
            'dias_por_ley'         => $diasAcumulados,
            'dias_anio_actual'     => $diasAnioActual,
            'dias_previos'         => $diasPrevios,
            'dias_tomados'         => $tomadosTotal,
            'dias_tomados_periodo' => $tomadosPeriodo,
            'saldo'                => max(0, $diasAcumulados - $tomadosTotal),
            'periodo_inicio'       => $periodoInicio->format('Y-m-d'),
            'periodo_fin'          => $periodoFin->format('Y-m-d'),
        ];
    }
}
