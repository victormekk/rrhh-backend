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

class RolesPermisosTest extends TestCase
{
    use RefreshDatabase;

    // Algunas migraciones antiguas insertan datos con id_usuario = 1 antes de que exista
    // ese usuario; en una base vacía solo se pueden correr sin revisar llaves foráneas.
    protected function beforeRefreshingDatabase()
    {
        // migrate:fresh vuelve a activar la revisión al borrar las tablas; se apaga al empezar a migrar.
        Event::listen(MigrationsStarted::class, fn () => DB::statement('SET FOREIGN_KEY_CHECKS=0'));
    }

    protected function afterRefreshingDatabase()
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }

    private function admin(): User
    {
        return User::factory()->create(['id_rol' => Rol::where('es_admin', true)->value('id'), 'activo' => true]);
    }

    private function conPermisos(array $permisos): User
    {
        $rol = Rol::create(['nombre' => 'Rol ' . uniqid(), 'permisos' => $permisos]);

        return User::factory()->create(['id_rol' => $rol->id, 'activo' => true]);
    }

    private function crearEmpleado(): int
    {
        $usuario = $this->admin()->id;
        $depto = DB::table('departamentos')->insertGetId(['nombre' => 'MERCADEO', 'id_usuario' => $usuario]);
        $cargo = DB::table('cargos')->insertGetId(['nombre' => 'ANALISTA', 'id_usuario' => $usuario]);
        $info = DB::table('informacion_laboral')->insertGetId([
            'tipo_contrato' => 'Fijo', 'fecha_inicio' => '2024-01-10', 'estado' => 'Activo',
            'moneda' => 'Lempiras', 'forma_de_pago' => 'Cheque',
            'salario_base' => 20000, 'salario_quincenal' => 10000, 'salario_diario' => 666.67, 'salario_por_hora' => 83.33,
            'usa_salario_minimo' => false, 'id_usuario' => $usuario,
        ]);

        return DB::table('empleados')->insertGetId([
            'nombres' => 'ANA', 'apellidos' => 'PAZ', 'cedula' => '0801-1990-00001', 'genero' => 'Femenino',
            'fecha_nacimiento' => '1990-' . now()->format('m') . '-01', 'edad' => 30, 'estado_civil' => 'Soltero/a',
            'num_hijos' => 0, 'nacionalidad' => 'HONDUREÑA', 'residencia' => 'X', 'telefono' => '0000-0000',
            'contacto_emergencia' => 'X', 'parentesco_emergencia' => 'OTRO', 'telefono_emergencia' => '0000-0000',
            'tipo_sangre' => 'O+', 'id_info_laboral' => $info, 'id_cargo' => $cargo, 'id_departamento' => $depto,
            'id_usuario' => $usuario,
        ]);
    }

    public function test_la_migracion_crea_administrador_y_rrhh(): void
    {
        $admin = Rol::where('nombre', 'Administrador')->first();
        $rrhh  = Rol::where('nombre', 'RRHH')->first();

        $this->assertTrue($admin->es_admin);
        $this->assertSame(Rol::todosLosPermisos(), $admin->permisosEfectivos());
        $this->assertContains('empleados.editar', $rrhh->permisosEfectivos());
        $this->assertNotContains('usuarios.ver', $rrhh->permisosEfectivos());
        $this->assertNotContains('planillas.eliminar', $rrhh->permisosEfectivos());
        // Todo permiso de RRHH existe en el catálogo.
        $this->assertSame([], array_diff($rrhh->permisos, Rol::todosLosPermisos()));
    }

    public function test_me_devuelve_rol_y_permisos(): void
    {
        Sanctum::actingAs($this->conPermisos(['cumpleanos.ver']));

        $this->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('permisos', ['cumpleanos.ver'])
            ->assertJsonPath('rol.es_admin', false);
    }

    public function test_sin_permiso_responde_403_y_con_permiso_deja_pasar(): void
    {
        Sanctum::actingAs($this->conPermisos(['cumpleanos.ver']));

        $this->getJson('/api/cumpleanos')->assertOk();
        $this->getJson('/api/empleados')->assertForbidden();
        $this->getJson('/api/planillas')->assertForbidden();
        $this->getJson('/api/usuarios')->assertForbidden();
        $this->getJson('/api/dashboard/planillas-chart')->assertForbidden();
        $this->getJson('/api/dashboard/stats')->assertOk();
    }

    public function test_sin_ver_salarios_no_se_mandan_salarios(): void
    {
        $id = $this->crearEmpleado();

        Sanctum::actingAs($this->conPermisos(['empleados.ver']));
        $sin = $this->getJson("/api/empleados/{$id}")->assertOk()->json('informacion_laboral');
        $this->assertArrayNotHasKey('salario_base', $sin);
        $this->assertArrayNotHasKey('salario_diario', $sin);
        $lista = $this->getJson('/api/empleados')->assertOk()->json('data.0.informacion_laboral');
        $this->assertArrayNotHasKey('salario_base', $lista);

        Sanctum::actingAs($this->conPermisos(['empleados.ver', 'salarios.ver']));
        $this->getJson("/api/empleados/{$id}")->assertOk()->assertJsonPath('informacion_laboral.salario_base', '20000.00');
    }

    public function test_bitacora_sin_permiso_solo_muestra_constancias(): void
    {
        $quien = $this->admin()->id;
        DB::table('bitacora')->insert([
            ['id_usuario' => $quien, 'accion' => 'generado', 'descripcion' => 'Constancia', 'objeto_actualizado' => 'Constancias', 'fecha' => now()->toDateString(), 'created_at' => now()],
            ['id_usuario' => $quien, 'accion' => 'editado', 'descripcion' => 'Usuario', 'objeto_actualizado' => 'Usuarios', 'fecha' => now()->toDateString(), 'created_at' => now()],
        ]);

        Sanctum::actingAs($this->conPermisos(['constancias.laboral']));
        $modulos = collect($this->getJson('/api/bitacora?modulo=Usuarios')->assertOk()->json('data'))->pluck('objeto_actualizado');
        $this->assertSame(['Constancias'], $modulos->unique()->values()->all());
    }

    public function test_crear_editar_y_eliminar_rol(): void
    {
        Sanctum::actingAs($this->admin());

        $id = $this->postJson('/api/roles', [
            'nombre' => 'Mercadeo', 'descripcion' => 'Cumpleaños', 'permisos' => ['cumpleanos.ver', 'empleados.ver'],
        ])->assertCreated()->json('id');

        $this->postJson('/api/roles', ['nombre' => 'Mercadeo', 'permisos' => []])->assertStatus(422);
        $this->postJson('/api/roles', ['nombre' => 'Otro', 'permisos' => ['no.existe']])->assertStatus(422);

        $this->putJson("/api/roles/{$id}", ['nombre' => 'Mercadeo', 'permisos' => ['cumpleanos.ver']])->assertOk();
        $this->assertSame(['cumpleanos.ver'], Rol::find($id)->permisos);

        User::factory()->create(['id_rol' => $id]);
        $this->deleteJson("/api/roles/{$id}")->assertStatus(422);

        User::where('id_rol', $id)->forceDelete();
        $this->deleteJson("/api/roles/{$id}")->assertOk();
        $this->assertNull(Rol::find($id));
    }

    public function test_el_rol_administrador_no_se_modifica_ni_elimina(): void
    {
        Sanctum::actingAs($this->admin());
        $idAdmin = Rol::where('es_admin', true)->value('id');

        $this->putJson("/api/roles/{$idAdmin}", ['nombre' => 'Admin', 'permisos' => []])->assertStatus(422);
        $this->deleteJson("/api/roles/{$idAdmin}")->assertStatus(422);
    }

    public function test_no_se_pueden_dar_permisos_que_uno_no_tiene(): void
    {
        $gestor = $this->conPermisos(['roles.ver', 'roles.crear', 'roles.editar', 'cumpleanos.ver']);
        Sanctum::actingAs($gestor);

        $id = $this->postJson('/api/roles', [
            'nombre' => 'Intento', 'permisos' => ['cumpleanos.ver', 'usuarios.eliminar'],
        ])->assertCreated()->json('id');
        $this->assertSame(['cumpleanos.ver'], Rol::find($id)->permisos);

        // Los permisos que el gestor no tiene se conservan al editar.
        $otro = Rol::create(['nombre' => 'Con planillas', 'permisos' => ['planillas.ver', 'cumpleanos.ver']]);
        $this->putJson("/api/roles/{$otro->id}", ['nombre' => 'Con planillas', 'permisos' => []])->assertOk();
        $this->assertSame(['planillas.ver'], $otro->fresh()->permisos);
    }

    public function test_solo_un_administrador_asigna_el_rol_administrador(): void
    {
        $idAdmin = Rol::where('es_admin', true)->value('id');
        Sanctum::actingAs($this->conPermisos(['usuarios.ver', 'usuarios.crear', 'usuarios.editar']));

        $this->postJson('/api/usuarios', [
            'name' => 'X', 'email' => 'x@hpr.hn', 'password' => '12345678', 'id_rol' => $idAdmin,
        ])->assertForbidden();

        $admin = $this->admin();
        $this->putJson("/api/usuarios/{$admin->id}", [
            'name' => 'Cambio', 'email' => $admin->email, 'id_rol' => Rol::where('nombre', 'RRHH')->value('id'),
        ])->assertForbidden();
    }

    public function test_nadie_cambia_su_propio_rol(): void
    {
        $admin = $this->admin();
        Sanctum::actingAs($admin);

        $this->putJson("/api/usuarios/{$admin->id}", [
            'name' => $admin->name, 'email' => $admin->email, 'id_rol' => Rol::where('nombre', 'RRHH')->value('id'),
        ])->assertStatus(422);
    }
}
