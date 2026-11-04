<?php

namespace Tests\Feature;

use App\Models\Rol;
use App\Models\User;
use Illuminate\Database\Events\MigrationsStarted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CumpleanosJubilacionesTest extends TestCase
{
    use RefreshDatabase;

    private int $depto;
    private int $cargo;
    private int $usuario;

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
        Carbon::setTestNow('2026-11-04 10:00:00');

        $admin = User::factory()->create(['id_rol' => Rol::where('es_admin', true)->value('id'), 'activo' => true]);
        $this->usuario = $admin->id;
        $this->depto = DB::table('departamentos')->insertGetId(['nombre' => 'COCINA', 'id_usuario' => $admin->id]);
        $this->cargo = DB::table('cargos')->insertGetId(['nombre' => 'COCINERO', 'id_usuario' => $admin->id]);
        Sanctum::actingAs($admin);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function empleado(string $nombre, string $nacimiento, string $estado = 'Activo'): int
    {
        static $n = 0;
        $n++;
        $info = DB::table('informacion_laboral')->insertGetId([
            'tipo_contrato' => 'Fijo', 'fecha_inicio' => '2010-01-10', 'estado' => $estado,
            'moneda' => 'Lempiras', 'forma_de_pago' => 'Cheque', 'salario_base' => 15000,
            'salario_quincenal' => 7500, 'salario_diario' => 500, 'salario_por_hora' => 62.5,
            'usa_salario_minimo' => false, 'id_usuario' => $this->usuario,
        ]);

        return DB::table('empleados')->insertGetId([
            'nombres' => $nombre, 'apellidos' => 'PRUEBA', 'cedula' => "0801-0000-{$n}", 'genero' => 'Masculino',
            'fecha_nacimiento' => $nacimiento, 'edad' => 0, 'estado_civil' => 'Soltero/a', 'num_hijos' => 0,
            'nacionalidad' => 'HONDUREÑO', 'residencia' => 'X', 'telefono' => '0000-0000',
            'contacto_emergencia' => 'X', 'parentesco_emergencia' => 'OTRO', 'telefono_emergencia' => '0000-0000',
            'tipo_sangre' => 'O+', 'id_info_laboral' => $info, 'id_cargo' => $this->cargo,
            'id_departamento' => $this->depto, 'id_usuario' => $this->usuario,
        ]);
    }

    public function test_jubilaciones_muestra_desde_los_59_y_ordena_por_el_mas_proximo(): void
    {
        $this->empleado('JOVEN', '1970-01-01');            // 56 años: no aparece
        $this->empleado('CINCUENTA Y NUEVE', '1967-03-15'); // 59: cumple 60 el 15/03/2027
        $this->empleado('CASI SESENTA', '1966-11-20');      // 59: cumple 60 en 16 días
        $this->empleado('SESENTA Y DOS', '1964-06-01');     // 62: cumple 65 el 01/06/2029
        $this->empleado('SETENTA', '1956-01-01');           // 70: ya tiene 65 o más
        $this->empleado('INACTIVO', '1960-01-01', 'Inactivo');

        $lista = $this->getJson('/api/dashboard/jubilaciones')->assertOk()->json();

        $this->assertSame(
            ['CASI SESENTA PRUEBA', 'CINCUENTA Y NUEVE PRUEBA', 'SESENTA Y DOS PRUEBA', 'SETENTA PRUEBA'],
            array_column($lista, 'nombre')
        );
        $this->assertSame([60, 16, '2026-11-20'], [$lista[0]['proximo'], $lista[0]['dias_proximo'], $lista[0]['fecha_60']]);
        $this->assertSame([65, '2029-06-01'], [$lista[2]['proximo'], $lista[2]['fecha_65']]);
        $this->assertNull($lista[3]['proximo']);
        $this->assertSame(70, $lista[3]['edad']);
    }

    public function test_jubilaciones_sin_empleados_devuelve_lista_vacia(): void
    {
        $this->empleado('JOVEN', '1990-05-05');

        $this->getJson('/api/dashboard/jubilaciones')->assertOk()->assertExactJson([]);
    }

    public function test_excel_de_cumpleanos_trae_los_del_mes_en_orden(): void
    {
        $this->empleado('SEGUNDO', '1990-11-20');
        $this->empleado('PRIMERO', '1985-11-03');
        $this->empleado('OTRO MES', '1985-10-03');

        $respuesta = $this->get('/api/cumpleanos/excel?mes=11')->assertOk();
        $hoja = \PhpOffice\PhpSpreadsheet\IOFactory::load($respuesta->baseResponse->getFile()->getPathname())->getActiveSheet();

        $this->assertSame('Nombre completo', $hoja->getCell('A5')->getValue());
        $this->assertSame(['PRIMERO PRUEBA', '3 de Noviembre', '41 años'],
            [$hoja->getCell('A6')->getValue(), $hoja->getCell('C6')->getValue(), $hoja->getCell('D6')->getValue()]);
        $this->assertSame('SEGUNDO PRUEBA', $hoja->getCell('A7')->getValue());
        $this->assertNull($hoja->getCell('A8')->getValue());
    }

    public function test_sin_permiso_no_se_exporta_ni_se_ve_la_lista(): void
    {
        $rol = Rol::create(['nombre' => 'Solo cumpleaños', 'permisos' => ['cumpleanos.ver']]);
        Sanctum::actingAs(User::factory()->create(['id_rol' => $rol->id, 'activo' => true]));

        $this->get('/api/cumpleanos/excel?mes=11')->assertForbidden();
        $this->getJson('/api/dashboard/jubilaciones')->assertForbidden();
    }
}
