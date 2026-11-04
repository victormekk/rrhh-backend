<?php

namespace Tests\Feature;

use App\Models\Rol;
use App\Models\SolicitudVacacion;
use App\Models\User;
use Illuminate\Database\Events\MigrationsStarted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\View;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class VacacionesAdelantoTest extends TestCase
{
    use RefreshDatabase;

    private int $empleado;

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
        Sanctum::actingAs($admin);

        $depto = DB::table('departamentos')->insertGetId(['nombre' => 'PISOS', 'id_usuario' => $admin->id]);
        $cargo = DB::table('cargos')->insertGetId(['nombre' => 'CAMARERA', 'id_usuario' => $admin->id]);
        // Entró el 1 de agosto de 2026: todavía no cumple su primer año (0 días ganados).
        $info = DB::table('informacion_laboral')->insertGetId([
            'tipo_contrato' => 'Fijo', 'fecha_inicio' => '2026-08-01', 'estado' => 'Activo',
            'moneda' => 'Lempiras', 'forma_de_pago' => 'Cheque', 'salario_base' => 15000,
            'salario_quincenal' => 7500, 'salario_diario' => 500, 'salario_por_hora' => 62.5,
            'usa_salario_minimo' => false, 'id_usuario' => $admin->id,
        ]);
        $this->empleado = DB::table('empleados')->insertGetId([
            'nombres' => 'ROSA', 'apellidos' => 'PRUEBA', 'cedula' => '0801-0000-1', 'genero' => 'Femenino',
            'fecha_nacimiento' => '1995-01-01', 'edad' => 31, 'estado_civil' => 'Soltero/a', 'num_hijos' => 0,
            'nacionalidad' => 'HONDUREÑA', 'residencia' => 'X', 'telefono' => '0000-0000',
            'contacto_emergencia' => 'X', 'parentesco_emergencia' => 'OTRO', 'telefono_emergencia' => '0000-0000',
            'tipo_sangre' => 'O+', 'id_info_laboral' => $info, 'id_cargo' => $cargo,
            'id_departamento' => $depto, 'id_usuario' => $admin->id,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // Lunes 7 al sábado 12 de diciembre de 2026: 6 días laborables, sin feriados.
    private function pedir(array $extra = [])
    {
        return $this->postJson('/api/vacaciones', [
            'id_empleado' => $this->empleado, 'fecha_inicio' => '2026-12-07', 'fecha_fin' => '2026-12-12', ...$extra,
        ]);
    }

    public function test_sin_saldo_pide_confirmar_el_adelanto(): void
    {
        $this->pedir()->assertStatus(422)->assertJsonPath('codigo', 'requiere_adelanto')->assertJsonPath('dias_adelantados', 6);
        $this->assertSame(0, SolicitudVacacion::count());
    }

    public function test_con_confirmacion_registra_el_adelanto_y_actualiza_el_saldo(): void
    {
        $this->pedir(['adelantar' => true])->assertCreated()->assertJsonPath('dias_adelantados', '6.00');

        $this->getJson("/api/vacaciones/saldo/{$this->empleado}")
            ->assertOk()
            ->assertJsonPath('saldo.saldo', 0)
            ->assertJsonPath('saldo.dias_adelantados', 6)
            ->assertJsonPath('saldo.adelanto_disponible', 4)
            ->assertJsonPath('saldo.proximo_periodo_inicio', '2027-08-01');
    }

    public function test_no_se_puede_adelantar_mas_de_los_dias_del_proximo_periodo(): void
    {
        // Lunes 7 al sábado 19 de diciembre: 12 días; el primer año solo da 10.
        $this->postJson('/api/vacaciones', [
            'id_empleado' => $this->empleado, 'fecha_inicio' => '2026-12-07', 'fecha_fin' => '2026-12-19', 'adelantar' => true,
        ])->assertStatus(422);
        $this->assertSame(0, SolicitudVacacion::count());
    }

    public function test_la_constancia_dice_que_es_adelanto_y_de_que_periodo(): void
    {
        $id = $this->pedir(['adelantar' => true])->assertCreated()->json('id');

        $datos = null;
        View::composer('vacaciones.solicitud', function ($vista) use (&$datos) {
            $datos ??= $vista->getData();
        });
        $this->get("/api/vacaciones/{$id}/pdf")->assertOk();

        $texto = preg_replace('/\s+/', ' ', strip_tags(view('vacaciones.solicitud', $datos)->render()));
        $this->assertStringContainsString('ADELANTO DE VACACIONES', $texto);
        $this->assertStringContainsString('6 días corresponden al período vacacional 2027–2028, que inicia el 01 de agosto de 2027', $texto);
    }

    public function test_con_saldo_no_se_puede_pedir_mas_del_saldo_aunque_se_confirme_adelanto(): void
    {
        // Con un año cumplido (10 días) ya tomó 6; le quedan 4 y pide 6: no se mezcla con adelanto.
        DB::table('informacion_laboral')->update(['fecha_inicio' => '2025-08-01']);
        $this->pedir()->assertCreated();

        $this->postJson('/api/vacaciones', [
            'id_empleado' => $this->empleado, 'fecha_inicio' => '2027-01-04', 'fecha_fin' => '2027-01-09', 'adelantar' => true,
        ])->assertStatus(422)->assertJsonPath('message', 'No hay suficientes días disponibles. Disponibles: 4 día(s), solicitados: 6.');
    }

    public function test_rechaza_anios_a_medio_escribir_pero_acepta_rangos_de_mas_de_un_anio(): void
    {
        // Año a medio escribir (0002): se rechaza sin contar días.
        $this->postJson('/api/vacaciones', [
            'id_empleado' => $this->empleado, 'fecha_inicio' => '0002-12-07', 'fecha_fin' => '2026-12-12', 'adelantar' => true,
        ])->assertStatus(422)->assertJsonValidationErrors('fecha_inicio');
        // Año mal escrito (2206): pasa del límite (año actual + 10).
        $this->postJson('/api/vacaciones', [
            'id_empleado' => $this->empleado, 'fecha_inicio' => '2026-12-07', 'fecha_fin' => '2206-12-12', 'adelantar' => true,
        ])->assertStatus(422)->assertJsonValidationErrors('fecha_fin');
        $this->assertSame(0, SolicitudVacacion::count());

        // 1995 sí se acepta como fecha (hay empleados que ingresaron antes del 2000).
        $this->postJson('/api/vacaciones', [
            'id_empleado' => $this->empleado, 'fecha_inicio' => '1995-07-03', 'fecha_fin' => '1995-07-04', 'adelantar' => true,
        ])->assertJsonMissingValidationErrors(['fecha_inicio', 'fecha_fin']);
        SolicitudVacacion::query()->forceDelete();

        // Más de un año (ajuste de saldos acumulados): se cuenta normal, sin límite de rango.
        DB::table('informacion_laboral')->update(['fecha_inicio' => '2006-08-01']); // 20 años: mucho saldo
        $this->postJson('/api/vacaciones', [
            'id_empleado' => $this->empleado, 'fecha_inicio' => '2026-01-05', 'fecha_fin' => '2027-03-01',
        ])->assertCreated();
    }

    public function test_una_solicitud_normal_no_lleva_adelanto(): void
    {
        // Con un año cumplido (10 días) pide 6: no hay adelanto ni aviso en la constancia.
        DB::table('informacion_laboral')->update(['fecha_inicio' => '2025-08-01']);

        $id = $this->pedir()->assertCreated()->assertJsonPath('dias_adelantados', '0.00')->json('id');

        $datos = null;
        View::composer('vacaciones.solicitud', function ($vista) use (&$datos) {
            $datos ??= $vista->getData();
        });
        $this->get("/api/vacaciones/{$id}/pdf")->assertOk();
        $this->assertStringNotContainsString('ADELANTO', view('vacaciones.solicitud', $datos)->render());
    }
}
