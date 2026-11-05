<?php

namespace Tests\Feature;

use App\Models\Rol;
use App\Models\User;
use Illuminate\Database\Events\MigrationsStarted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SalariosDevengadosTest extends TestCase
{
    use RefreshDatabase;

    private int $usuario;
    private int $depto;
    private int $cargo;

    // Ver RolesPermisosTest: las migraciones antiguas necesitan correr sin llaves foráneas.
    protected function beforeRefreshingDatabase()
    {
        Event::listen(MigrationsStarted::class, fn () => DB::statement('SET FOREIGN_KEY_CHECKS=0'));
    }

    protected function afterRefreshingDatabase()
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }

    protected function setUp(): void
    {
        parent::setUp();
        $admin = User::factory()->create(['id_rol' => Rol::where('es_admin', true)->value('id'), 'activo' => true]);
        Sanctum::actingAs($admin);
        $this->usuario = $admin->id;
        $this->depto = DB::table('departamentos')->insertGetId(['nombre' => 'BARES', 'id_usuario' => $admin->id]);
        $this->cargo = DB::table('cargos')->insertGetId(['nombre' => 'BARTENDER', 'id_usuario' => $admin->id]);
    }

    private function empleado(string $nombre, string $inicio, float $salario): int
    {
        static $n = 0;
        $n++;
        $info = DB::table('informacion_laboral')->insertGetId([
            'tipo_contrato' => 'Fijo', 'fecha_inicio' => $inicio, 'estado' => 'Activo',
            'moneda' => 'Lempiras', 'forma_de_pago' => 'Cheque', 'salario_base' => $salario,
            'salario_quincenal' => $salario / 2, 'salario_diario' => round($salario / 30, 2), 'salario_por_hora' => 1,
            'usa_salario_minimo' => false, 'id_usuario' => $this->usuario,
        ]);

        return DB::table('empleados')->insertGetId([
            'nombres' => $nombre, 'apellidos' => 'PRUEBA', 'cedula' => "0801-2000-{$n}", 'genero' => 'Masculino',
            'fecha_nacimiento' => '1990-01-01', 'edad' => 36, 'estado_civil' => 'Soltero/a', 'num_hijos' => 0,
            'nacionalidad' => 'HONDUREÑO', 'residencia' => 'X', 'telefono' => '0000-0000',
            'contacto_emergencia' => 'X', 'parentesco_emergencia' => 'OTRO', 'telefono_emergencia' => '0000-0000',
            'tipo_sangre' => 'O+', 'id_info_laboral' => $info, 'id_cargo' => $this->cargo,
            'id_departamento' => $this->depto, 'id_usuario' => $this->usuario,
        ]);
    }

    private function planilla(int $empleado, string $fecha, float $base, float $extras, float $otros, float $deduccion): void
    {
        $cab = DB::table('cabecera_planillas')->insertGetId([
            'nombre_planilla' => "P {$fecha} {$empleado}", 'tipo_planilla' => 'Fijos', 'estado' => 'Cerrado',
            'fecha_generada' => $fecha, 'id_usuario' => $this->usuario,
        ]);
        DB::table('detalle_planillas')->insert([
            'id_cabecera_planilla' => $cab, 'id_empleado' => $empleado, 'nombre_planilla' => "P {$fecha}",
            'departamento' => 'BARES', 'tipo_planilla' => 'Fijos', 'dias_trabajados' => 15,
            'salario_diario' => 500, 'salario_base' => $base, 'monto_horas_extras' => $extras,
            'otros_ingresos' => $otros, 'deduccion_neta' => $deduccion,
            'salario_neto' => $base + $extras + $otros - $deduccion,
            'fecha_generada' => $fecha, 'id_usuario' => $this->usuario,
        ]);
    }

    public function test_suma_lo_devengado_en_el_rango_y_el_neto(): void
    {
        $a = $this->empleado('ANA', '2024-01-01', 15000);
        $b = $this->empleado('BETO', '2024-01-01', 20000);

        $this->planilla($a, '2026-10-15', 7500, 300, 200, 500);   // devengado 8000, neto 7500
        $this->planilla($a, '2026-10-31', 7500, 0, 0, 500);       // devengado 7500, neto 7000
        $this->planilla($a, '2026-09-30', 7500, 0, 0, 0);         // fuera del rango

        $r = $this->getJson('/api/salarios-devengados?' . http_build_query([
            'ids' => [$a, $b], 'fecha_inicio' => '2026-10-01', 'fecha_fin' => '2026-10-31',
        ]))->assertOk();

        $filas = collect($r->json('filas'))->keyBy('nombre');
        $this->assertEquals(15500, $filas['ANA PRUEBA']['devengado']);
        $this->assertEquals(14500, $filas['ANA PRUEBA']['neto']);
        $this->assertEquals(15000, $filas['ANA PRUEBA']['salario_mensual']);
        $this->assertSame(2, $filas['ANA PRUEBA']['quincenas']);
        // Sin planillas en el rango: aparece en cero.
        $this->assertEquals(0, $filas['BETO PRUEBA']['devengado']);
        $this->assertEquals(15500, $r->json('totales.devengado'));
        $this->assertEquals(35000, $r->json('totales.salario_mensual'));
    }

    public function test_no_cuenta_planillas_anteriores_a_su_fecha_de_inicio_actual(): void
    {
        // Reingresó el 20/10: la planilla del 15/10 es de su período anterior.
        $a = $this->empleado('CARLA', '2026-10-20', 15000);
        $this->planilla($a, '2026-10-15', 7500, 0, 0, 0);
        $this->planilla($a, '2026-10-31', 3000, 0, 0, 0);

        $r = $this->getJson('/api/salarios-devengados?' . http_build_query([
            'ids' => [$a], 'fecha_inicio' => '2026-10-01', 'fecha_fin' => '2026-10-31',
        ]))->assertOk();
        $this->assertEquals(3000, $r->json('filas.0.devengado'));
    }

    public function test_excel_y_permisos(): void
    {
        $a = $this->empleado('ANA', '2024-01-01', 15000);
        $this->planilla($a, '2026-10-15', 7500, 0, 0, 500);
        $q = http_build_query(['ids' => [$a], 'fecha_inicio' => '2026-10-01', 'fecha_fin' => '2026-10-31']);

        $hoja = \PhpOffice\PhpSpreadsheet\IOFactory::load(
            $this->get("/api/salarios-devengados/excel?{$q}")->assertOk()->baseResponse->getFile()->getPathname()
        )->getActiveSheet();
        $this->assertSame(['Nombre completo', 'Salario devengado'], [$hoja->getCell('A5')->getValue(), $hoja->getCell('D5')->getValue()]);
        $this->assertSame('ANA PRUEBA', $hoja->getCell('A6')->getValue());
        $this->assertEquals(7500, $hoja->getCell('D6')->getValue());
        $this->assertSame('TOTAL', $hoja->getCell('A7')->getValue());

        // Un rol sin el permiso no puede ver ni exportar.
        $rol = Rol::create(['nombre' => 'Sin devengados', 'permisos' => ['estadistica.ver']]);
        Sanctum::actingAs(User::factory()->create(['id_rol' => $rol->id, 'activo' => true]));
        $this->getJson("/api/salarios-devengados?{$q}")->assertForbidden();
        $this->get("/api/salarios-devengados/excel?{$q}")->assertForbidden();
    }
}
