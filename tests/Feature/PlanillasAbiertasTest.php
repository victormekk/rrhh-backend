<?php

namespace Tests\Feature;

use App\Models\DetallePlanilla;
use App\Models\IngresoDeduccion;
use App\Models\Rol;
use App\Models\User;
use Illuminate\Database\Events\MigrationsStarted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

// Las planillas de pago abiertas se mantienen al día sin volver a crearlas: entran y
// salen empleados al darlos de baja o reintegrarlos, y los préstamos/deducciones se
// cargan o se quitan al registrarlos, pausarlos o cancelarlos.
class PlanillasAbiertasTest extends TestCase
{
    use RefreshDatabase;

    private int $admin;
    private int $rosa;
    private int $planilla;

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
        Carbon::setTestNow('2026-11-19 10:00:00');

        $admin = User::factory()->create(['id_rol' => Rol::where('es_admin', true)->value('id'), 'activo' => true]);
        Sanctum::actingAs($admin);
        $this->admin = $admin->id;

        $this->rosa = $this->empleado('ROSA', 'Fijo');

        $this->planilla = $this->postJson('/api/planillas', [
            'nombre_planilla' => 'Planilla 30 de noviembre', 'tipo_planilla' => 'Fijos', 'fecha_generada' => '2026-11-30',
        ])->assertCreated()->json('id');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function empleado(string $nombre, string $contrato): int
    {
        $depto = DB::table('departamentos')->insertGetId(['nombre' => "D{$nombre}", 'id_usuario' => $this->admin]);
        $cargo = DB::table('cargos')->insertGetId(['nombre' => "C{$nombre}", 'id_usuario' => $this->admin]);
        $info  = DB::table('informacion_laboral')->insertGetId([
            'tipo_contrato' => $contrato, 'fecha_inicio' => '2025-01-01', 'estado' => 'Activo',
            'moneda' => 'Lempiras', 'forma_de_pago' => 'Cheque', 'salario_base' => 15000,
            'salario_quincenal' => 7500, 'salario_diario' => 500, 'salario_por_hora' => 62.5,
            'usa_salario_minimo' => false, 'id_usuario' => $this->admin,
        ]);

        return DB::table('empleados')->insertGetId([
            'nombres' => $nombre, 'apellidos' => 'PRUEBA', 'cedula' => "0801-{$nombre}", 'genero' => 'Femenino',
            'fecha_nacimiento' => '1995-01-01', 'edad' => 31, 'estado_civil' => 'Soltero/a', 'num_hijos' => 0,
            'nacionalidad' => 'HONDUREÑA', 'residencia' => 'X', 'telefono' => '0000-0000',
            'contacto_emergencia' => 'X', 'parentesco_emergencia' => 'OTRO', 'telefono_emergencia' => '0000-0000',
            'tipo_sangre' => 'O+', 'id_info_laboral' => $info, 'id_cargo' => $cargo,
            'id_departamento' => $depto, 'id_usuario' => $this->admin,
        ]);
    }

    private function fila(int $empleado): ?DetallePlanilla
    {
        return DetallePlanilla::where('id_cabecera_planilla', $this->planilla)->where('id_empleado', $empleado)->first();
    }

    private function prestamo(int $empleado, float $monto)
    {
        return $this->postJson('/api/ingresos-deducciones', [
            'id_empleado' => $empleado, 'tipo' => 'Deduccion', 'aplica_en' => 'Planilla', 'concepto' => 'Prestamo',
            'columna' => 'otras_deducciones', 'monto' => $monto, 'frecuencia' => 'Quincenal',
            'total_cuotas' => 10, 'fecha_inicio' => '2026-11-01',
        ]);
    }

    public function test_al_dar_de_baja_sale_y_al_reintegrar_vuelve_con_sus_deducciones(): void
    {
        $this->prestamo($this->rosa, 500)->assertCreated();

        $this->postJson("/api/empleados/{$this->rosa}/cese", [
            'fecha_cese' => '2026-11-20', 'motivo_cese' => 'Renuncia', 'liquidacion' => 'Pendiente',
        ])->assertOk()->assertJsonPath('planillas', 'Quitado de la planilla abierta: Planilla 30 de noviembre.');

        $this->assertNull($this->fila($this->rosa));
        $this->assertSame(0, IngresoDeduccion::first()->aplicaciones()->count());

        $this->postJson("/api/empleados/{$this->rosa}/reintegro", [
            'fecha_inicio' => '2026-11-25', 'tipo_contrato' => 'Fijo', 'motivo' => 'Regresa a trabajar',
        ])->assertOk()->assertJsonPath('planillas', 'Agregado a la planilla abierta: Planilla 30 de noviembre.');

        $fila = $this->fila($this->rosa);
        $this->assertEquals(500, $fila->otras_deducciones);
        $this->assertSame('Prestamo: 500', $fila->desc_otras_deducciones);
        $this->assertEqualsWithDelta(797.58, (float) $fila->deduccion_neta, 0.001);
    }

    public function test_reintegro_como_extra_o_con_inicio_posterior_no_entra_en_la_de_fijos(): void
    {
        $this->postJson("/api/empleados/{$this->rosa}/cese", [
            'fecha_cese' => '2026-11-20', 'motivo_cese' => 'Renuncia', 'liquidacion' => 'No',
        ])->assertOk();

        // Empieza el 1 de diciembre: no va en la planilla del 30 de noviembre.
        $this->postJson("/api/empleados/{$this->rosa}/reintegro", [
            'fecha_inicio' => '2026-12-01', 'tipo_contrato' => 'Fijo', 'motivo' => 'Regresa en diciembre',
        ])->assertOk()->assertJsonPath('planillas', null);
        $this->assertNull($this->fila($this->rosa));
    }

    public function test_un_prestamo_se_carga_al_registrarlo_y_sale_al_pausarlo_o_cancelarlo(): void
    {
        $fila = $this->fila($this->rosa);
        // Una deducción puesta a mano en la misma celda no se toca.
        $this->putJson("/api/planillas/{$this->planilla}/detalles/{$fila->id}", [
            'otras_deducciones' => 200, 'desc_otras_deducciones' => 'Uniforme roto: 200',
        ])->assertOk();

        $id = $this->prestamo($this->rosa, 500)->assertCreated()
            ->assertJsonPath('cargado_en', 'Planilla 30 de noviembre')->json('id');
        $this->assertEquals(700, $fila->fresh()->otras_deducciones);
        $this->assertSame('Uniforme roto: 200, Prestamo: 500', $fila->fresh()->desc_otras_deducciones);

        $this->patchJson("/api/ingresos-deducciones/{$id}/estado", ['estado' => 'Pausado'])->assertOk();
        $this->assertEquals(200, $fila->fresh()->otras_deducciones);
        $this->assertSame('Uniforme roto: 200', $fila->fresh()->desc_otras_deducciones);
        $this->assertEqualsWithDelta(-497.58, (float) $fila->fresh()->salario_neto, 0.001); // 0 días trabajados

        $this->patchJson("/api/ingresos-deducciones/{$id}/estado", ['estado' => 'Activo'])->assertOk()
            ->assertJsonPath('cargado_en', 'Planilla 30 de noviembre');
        $this->assertEquals(700, $fila->fresh()->otras_deducciones);

        // Editar el monto lo actualiza en la planilla abierta.
        $this->putJson("/api/ingresos-deducciones/{$id}", [
            'id_empleado' => $this->rosa, 'tipo' => 'Deduccion', 'aplica_en' => 'Planilla', 'concepto' => 'Prestamo',
            'columna' => 'otras_deducciones', 'monto' => 300, 'frecuencia' => 'Quincenal',
            'total_cuotas' => 10, 'fecha_inicio' => '2026-11-01',
        ])->assertOk();
        $this->assertEquals(500, $fila->fresh()->otras_deducciones);
        $this->assertSame('Uniforme roto: 200, Prestamo: 300', $fila->fresh()->desc_otras_deducciones);

        $this->patchJson("/api/ingresos-deducciones/{$id}/estado", ['estado' => 'Cancelado'])->assertOk();
        $this->assertEquals(200, $fila->fresh()->otras_deducciones);

        // Solo estaba pendiente: al cerrar no cuenta como pagada.
        $this->postJson("/api/planillas/{$this->planilla}/cerrar")->assertOk();
        $this->assertSame(0, IngresoDeduccion::find($id)->aplicaciones()->count());
    }

    public function test_si_ya_se_quito_a_mano_no_resta_dos_veces(): void
    {
        $id   = $this->prestamo($this->rosa, 500)->assertCreated()->json('id');
        $fila = $this->fila($this->rosa);

        $this->putJson("/api/planillas/{$this->planilla}/detalles/{$fila->id}", [
            'otras_deducciones' => 100, 'desc_otras_deducciones' => 'Otro: 100',
        ])->assertOk();

        $this->patchJson("/api/ingresos-deducciones/{$id}/estado", ['estado' => 'Cancelado'])->assertOk();
        $this->assertEquals(100, $fila->fresh()->otras_deducciones);
    }
}
