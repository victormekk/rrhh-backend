<?php

namespace Tests\Unit;

use App\Models\DetallePlanilla;
use PHPUnit\Framework\TestCase;

class HorasExtrasTest extends TestCase
{
    // Sin marcar recargo la hora extra se paga a salario diario ÷ 8, como siempre.
    public function test_sin_recargo_es_diario_entre_8_por_horas(): void
    {
        $this->assertSame(135.98, DetallePlanilla::montoHorasExtras(543.92, 2, 0));
        $this->assertSame(0.0, DetallePlanilla::montoHorasExtras(543.92, 0, 75));
    }

    public function test_recargos_diurna_nocturna_y_75(): void
    {
        $this->assertSame(671.25, DetallePlanilla::montoHorasExtras(477.33, 9, 25));  // Hora Diurna
        $this->assertSame(203.97, DetallePlanilla::montoHorasExtras(543.92, 2, 50));  // Hora Nocturna
        $this->assertSame(237.96, DetallePlanilla::montoHorasExtras(543.92, 2, 75));  // Hora Extra 75%
    }
}
