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

class EstadisticaLaboralFiltrosTest extends TestCase
{
    use RefreshDatabase;

    private int $usuario;
    private int $bares;
    private int $cocina;
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
        $this->bares  = DB::table('departamentos')->insertGetId(['nombre' => 'BARES', 'id_usuario' => $admin->id]);
        $this->cocina = DB::table('departamentos')->insertGetId(['nombre' => 'COCINA', 'id_usuario' => $admin->id]);
        $this->cargo  = DB::table('cargos')->insertGetId(['nombre' => 'AUXILIAR', 'id_usuario' => $admin->id]);
    }

    private function empleado(string $nombre, int $depto, string $contrato, string $estado): int
    {
        static $n = 0;
        $n++;
        $info = DB::table('informacion_laboral')->insertGetId([
            'tipo_contrato' => $contrato, 'fecha_inicio' => '2026-01-01', 'estado' => $estado,
            'moneda' => 'Lempiras', 'forma_de_pago' => 'Cheque', 'salario_base' => 15000,
            'salario_quincenal' => 7500, 'salario_diario' => 500, 'salario_por_hora' => 1,
            'usa_salario_minimo' => false, 'id_usuario' => $this->usuario,
        ]);

        $id = DB::table('empleados')->insertGetId([
            'nombres' => $nombre, 'apellidos' => 'PRUEBA', 'cedula' => "0801-2001-{$n}", 'genero' => 'Masculino',
            'fecha_nacimiento' => '1990-01-01', 'edad' => 36, 'estado_civil' => 'Soltero/a', 'num_hijos' => 0,
            'nacionalidad' => 'HONDUREÑO', 'residencia' => 'X', 'telefono' => '0000-0000',
            'contacto_emergencia' => 'X', 'parentesco_emergencia' => 'OTRO', 'telefono_emergencia' => '0000-0000',
            'tipo_sangre' => 'O+', 'id_info_laboral' => $info, 'id_cargo' => $this->cargo,
            'id_departamento' => $depto, 'id_usuario' => $this->usuario,
        ]);

        $cab = DB::table('cabecera_planillas')->insertGetId([
            'nombre_planilla' => "P {$id}", 'tipo_planilla' => 'Fijos', 'estado' => 'Cerrado',
            'fecha_generada' => '2026-03-15', 'id_usuario' => $this->usuario,
        ]);
        DB::table('detalle_planillas')->insert([
            'id_cabecera_planilla' => $cab, 'id_empleado' => $id, 'nombre_planilla' => "P {$id}",
            'departamento' => 'X', 'tipo_planilla' => 'Fijos', 'dias_trabajados' => 15,
            'salario_diario' => 500, 'salario_base' => 7500, 'monto_horas_extras' => 0,
            'otros_ingresos' => 0, 'deduccion_neta' => 0, 'salario_neto' => 7500,
            'fecha_generada' => '2026-03-15', 'id_usuario' => $this->usuario,
        ]);

        return $id;
    }

    private function nombres(array $params): array
    {
        return collect($this->getJson('/api/estadistica-laboral?' . http_build_query($params))->assertOk()->json('data'))
            ->pluck('nombres')->sort()->values()->all();
    }

    public function test_filtra_por_departamento_contrato_y_estado(): void
    {
        $this->empleado('ANA', $this->bares, 'Fijo', 'Activo');
        $this->empleado('BETO', $this->bares, 'Extra', 'Activo');
        $this->empleado('CARLA', $this->cocina, 'Fijo', 'Inactivo');

        $this->assertSame(['ANA', 'BETO', 'CARLA'], $this->nombres([]));
        $this->assertSame(['ANA', 'BETO'], $this->nombres(['departamentos' => [$this->bares]]));
        $this->assertSame(['ANA', 'CARLA'], $this->nombres(['tipo_contrato' => 'Fijo']));
        $this->assertSame(['CARLA'], $this->nombres(['estado' => 'Inactivo']));
        $this->assertSame(['ANA'], $this->nombres(['departamentos' => [$this->bares], 'tipo_contrato' => 'Fijo', 'estado' => 'Activo']));
    }

    public function test_el_pdf_respeta_los_filtros(): void
    {
        $this->empleado('ANA', $this->bares, 'Fijo', 'Activo');

        $this->get('/api/estadistica-laboral/pdf?' . http_build_query(['departamentos' => [$this->bares], 'estado' => 'Activo']))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }
}
