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

class ListadoCumpleanosTest extends TestCase
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
        \Illuminate\Support\Carbon::setTestNow('2026-11-05 10:00:00');
        $admin = User::factory()->create(['id_rol' => Rol::where('es_admin', true)->value('id'), 'activo' => true]);
        Sanctum::actingAs($admin);
        $this->usuario = $admin->id;
        $this->depto = DB::table('departamentos')->insertGetId(['nombre' => 'BARES', 'id_usuario' => $admin->id]);
        $this->cargo = DB::table('cargos')->insertGetId(['nombre' => 'BARTENDER', 'id_usuario' => $admin->id]);
    }

    private function empleado(string $nombre, string $nacimiento, string $estado = 'Activo', ?int $depto = null): int
    {
        static $n = 0;
        $n++;
        $info = DB::table('informacion_laboral')->insertGetId([
            'tipo_contrato' => 'Fijo', 'fecha_inicio' => '2020-01-01', 'estado' => $estado,
            'moneda' => 'Lempiras', 'forma_de_pago' => 'Cheque', 'salario_base' => 15000,
            'salario_quincenal' => 7500, 'salario_diario' => 500, 'salario_por_hora' => 1,
            'usa_salario_minimo' => false, 'id_usuario' => $this->usuario,
        ]);

        return DB::table('empleados')->insertGetId([
            'nombres' => $nombre, 'apellidos' => 'PRUEBA', 'cedula' => "0801-2000-{$n}", 'genero' => 'Masculino',
            'fecha_nacimiento' => $nacimiento, 'edad' => 36, 'estado_civil' => 'Soltero/a', 'num_hijos' => 0,
            'nacionalidad' => 'HONDUREÑO', 'residencia' => 'X', 'telefono' => '0000-0000',
            'contacto_emergencia' => 'X', 'parentesco_emergencia' => 'OTRO', 'telefono_emergencia' => '0000-0000',
            'tipo_sangre' => 'O+', 'id_info_laboral' => $info, 'id_cargo' => $this->cargo,
            'id_departamento' => $depto ?? $this->depto, 'id_usuario' => $this->usuario,
        ]);
    }

    public function test_lista_activos_del_mes_por_dia_con_su_edad(): void
    {
        $this->empleado('CARLOS', '1990-03-20');
        $this->empleado('ANA', '1985-03-05');
        $this->empleado('BETO', '1992-04-01');
        $this->empleado('INACTIVO', '1990-03-10', 'Inactivo');

        $r = $this->getJson('/api/listado-cumpleanos?mes=3')->assertOk();
        $this->assertSame(['ANA PRUEBA', 'CARLOS PRUEBA'], array_column($r->json(), 'nombre'));
        $this->assertSame('5 de Marzo', $r->json('0.cumpleanos'));
        $this->assertSame(41, $r->json('0.edad')); // nació en 1985; este año (2026) cumple 41
    }

    public function test_todo_el_anio_y_por_departamento(): void
    {
        $otro = DB::table('departamentos')->insertGetId(['nombre' => 'COCINA', 'id_usuario' => $this->usuario]);
        $this->empleado('BETO', '1992-04-01');
        $this->empleado('ANA', '1985-03-05', 'Activo', $otro);
        $this->empleado('CARLOS', '1990-12-20');

        $todos = $this->getJson('/api/listado-cumpleanos?mes=0')->assertOk()->json();
        $this->assertSame(['ANA PRUEBA', 'BETO PRUEBA', 'CARLOS PRUEBA'], array_column($todos, 'nombre'));

        $cocina = $this->getJson('/api/listado-cumpleanos?' . http_build_query(['mes' => 0, 'departamentos' => [$otro]]))->json();
        $this->assertSame(['ANA PRUEBA'], array_column($cocina, 'nombre'));
    }

    public function test_excel_y_permisos(): void
    {
        $this->empleado('ANA', '1985-03-05');

        $hoja = \PhpOffice\PhpSpreadsheet\IOFactory::load(
            $this->get('/api/listado-cumpleanos/excel?mes=3')->assertOk()->baseResponse->getFile()->getPathname()
        )->getActiveSheet();
        $fila = fn (int $n) => array_map(fn ($c) => $hoja->getCell("{$c}{$n}")->getValue(), ['A', 'B', 'C', 'D', 'E', 'F']);
        $this->assertSame(['Nombre completo', 'Departamento', 'Cumpleaños', 'Edad que cumple', 'Teléfono', null], $fila(5));
        $this->assertSame(['ANA PRUEBA', 'BARES', '5 de Marzo', '41 años', '0000-0000', null], $fila(6));

        $rol = Rol::create(['nombre' => 'Sin listado', 'permisos' => ['cumpleanos.ver']]);
        Sanctum::actingAs(User::factory()->create(['id_rol' => $rol->id, 'activo' => true]));
        $this->getJson('/api/listado-cumpleanos?mes=3')->assertForbidden();
        $this->get('/api/listado-cumpleanos/excel?mes=3')->assertForbidden();
    }
}
