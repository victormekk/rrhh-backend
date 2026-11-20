<?php

namespace Tests\Feature;

use App\Models\AguinaldoExtra;
use App\Models\AguinaldoFijo;
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

// Anticipos de aguinaldo/catorceavo registrados en Ingresos/Deducciones: se
// descuentan solos en la columna Anticipo de la planilla especial que corresponde.
class AnticiposAguinaldoTest extends TestCase
{
    use RefreshDatabase;

    private int $fijo;
    private int $extra;

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

        $depto = DB::table('departamentos')->insertGetId(['nombre' => 'PISOS', 'id_usuario' => $admin->id]);
        $cargo = DB::table('cargos')->insertGetId(['nombre' => 'CAMARERA', 'id_usuario' => $admin->id]);

        // Fijo con año completo: aguinaldo = salario (15,000).
        $this->fijo = $this->empleado($admin->id, $depto, $cargo, 'ROSA', [
            'tipo_contrato' => 'Fijo', 'fecha_inicio' => '2025-01-01', 'salario_base' => 15000,
            'salario_quincenal' => 7500, 'salario_diario' => 500, 'salario_por_hora' => 62.5,
        ]);
        // Extra sin promedio de días y con antigüedad completa: 400 × 30 = 12,000.
        $this->extra = $this->empleado($admin->id, $depto, $cargo, 'LUIS', [
            'tipo_contrato' => 'Extra', 'fecha_inicio' => '2024-01-01', 'salario_base' => 12000,
            'salario_quincenal' => 6000, 'salario_diario' => 400, 'salario_por_hora' => 50,
            'sin_promedio_dias' => true,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function empleado(int $usuario, int $depto, int $cargo, string $nombre, array $laboral): int
    {
        $info = DB::table('informacion_laboral')->insertGetId([
            'estado' => 'Activo', 'moneda' => 'Lempiras', 'forma_de_pago' => 'Cheque',
            'usa_salario_minimo' => false, 'id_usuario' => $usuario, ...$laboral,
        ]);

        return DB::table('empleados')->insertGetId([
            'nombres' => $nombre, 'apellidos' => 'PRUEBA', 'cedula' => "0801-{$nombre}", 'genero' => 'Femenino',
            'fecha_nacimiento' => '1995-01-01', 'edad' => 31, 'estado_civil' => 'Soltero/a', 'num_hijos' => 0,
            'nacionalidad' => 'HONDUREÑA', 'residencia' => 'X', 'telefono' => '0000-0000',
            'contacto_emergencia' => 'X', 'parentesco_emergencia' => 'OTRO', 'telefono_emergencia' => '0000-0000',
            'tipo_sangre' => 'O+', 'id_info_laboral' => $info, 'id_cargo' => $cargo,
            'id_departamento' => $depto, 'id_usuario' => $usuario,
        ]);
    }

    private function anticipo(int $empleado, string $aplicaEn, float $monto)
    {
        return $this->postJson('/api/ingresos-deducciones', [
            'id_empleado' => $empleado, 'tipo' => 'Deduccion', 'aplica_en' => $aplicaEn,
            'concepto' => 'Anticipo', 'monto' => $monto, 'fecha_inicio' => '2026-11-19',
        ]);
    }

    private function generar(string $nombre, string $concepto, string $tipo)
    {
        return $this->postJson('/api/aguinaldo', [
            'nombre_aguinaldo' => $nombre, 'concepto' => $concepto, 'tipo_aguinaldo' => $tipo,
            'fecha_generada' => '2026-12-15', 'fecha_corte' => '2026-12-31',
        ]);
    }

    public function test_se_descuenta_solo_en_el_concepto_elegido_y_se_completa_al_cerrar(): void
    {
        $this->anticipo($this->fijo, 'Aguinaldo', 3000)->assertCreated()->assertJsonPath('cargado_en', null);
        $this->anticipo($this->fijo, 'Catorceavo', 1000)->assertCreated();

        $this->generar('Aguinaldo 2026', 'Aguinaldo', 'Fijos')->assertCreated()->assertJsonPath('anticipos_cargados', 1);

        $fila = AguinaldoFijo::where('id_empleado', $this->fijo)->first();
        $this->assertEquals(3000, $fila->anticipo);
        $this->assertEquals(12000, $fila->total_aguinaldo);

        $this->getJson('/api/aguinaldo/Aguinaldo 2026')->assertOk()
            ->assertJsonPath("anticipos_programados.{$this->fijo}.0.monto", 3000);

        // Registrado con el aguinaldo ya abierto: se carga ahí mismo.
        $otro = $this->anticipo($this->fijo, 'Aguinaldo', 500)->assertCreated()
            ->assertJsonPath('cargado_en', 'Aguinaldo 2026')->json('id');
        $this->assertEquals(3500, $fila->fresh()->anticipo);
        $this->assertEquals(11500, $fila->fresh()->total_aguinaldo);

        // Pausado sale del aguinaldo abierto.
        $this->patchJson("/api/ingresos-deducciones/{$otro}/estado", ['estado' => 'Pausado'])->assertOk();
        $this->assertEquals(3000, $fila->fresh()->anticipo);
        $this->assertEquals(12000, $fila->fresh()->total_aguinaldo);

        $this->postJson('/api/aguinaldo/Aguinaldo 2026/cerrar')->assertOk();

        $this->assertSame('Completado', IngresoDeduccion::where('monto', 3000)->value('estado'));
        $this->assertSame('Activo', IngresoDeduccion::where('aplica_en', 'Catorceavo')->value('estado'));
        $this->assertSame('Pausado', IngresoDeduccion::find($otro)->estado);
    }

    public function test_en_extras_recalcula_el_total_y_al_editar_el_monto_se_actualiza(): void
    {
        $id = $this->anticipo($this->extra, 'Catorceavo', 2000)->assertCreated()->json('id');
        $this->generar('Catorceavo 2026', 'Catorceavo', 'Extras')->assertCreated()->assertJsonPath('anticipos_cargados', 1);

        $fila = AguinaldoExtra::where('id_empleado', $this->extra)->first();
        $this->assertEquals(2000, $fila->anticipos);
        $this->assertEquals(10000, $fila->total_aguinaldo);

        $this->putJson("/api/ingresos-deducciones/{$id}", [
            'id_empleado' => $this->extra, 'tipo' => 'Deduccion', 'aplica_en' => 'Catorceavo',
            'concepto' => 'Anticipo', 'monto' => 2500, 'fecha_inicio' => '2026-11-19',
        ])->assertOk()->assertJsonPath('cargado_en', 'Catorceavo 2026');

        $this->assertEquals(2500, $fila->fresh()->anticipos);
        $this->assertEquals(9500, $fila->fresh()->total_aguinaldo);
    }

    public function test_si_se_quita_a_mano_o_se_anula_el_lote_queda_pendiente(): void
    {
        $this->anticipo($this->fijo, 'Aguinaldo', 3000)->assertCreated();

        // Anular el lote lo libera para el siguiente.
        $this->generar('Aguinaldo A', 'Aguinaldo', 'Fijos')->assertCreated();
        $this->deleteJson('/api/aguinaldo/Aguinaldo A')->assertOk();
        $this->generar('Aguinaldo B', 'Aguinaldo', 'Fijos')->assertCreated()->assertJsonPath('anticipos_cargados', 1);

        // Quitado a mano de la celda: al cerrar no cuenta y sigue Activo.
        $fila = AguinaldoFijo::where('nombre_aguinaldo', 'Aguinaldo B')->first();
        $this->putJson("/api/aguinaldo/fijos/{$fila->id}", ['anticipo' => 0])->assertOk();
        $this->postJson('/api/aguinaldo/Aguinaldo B/cerrar')->assertOk();

        $registro = IngresoDeduccion::first();
        $this->assertSame('Activo', $registro->estado);
        $this->assertSame(0, $registro->aplicaciones()->count());
    }

    public function test_no_entra_en_la_planilla_quincenal_ni_puede_ser_ingreso(): void
    {
        $this->postJson('/api/ingresos-deducciones', [
            'id_empleado' => $this->fijo, 'tipo' => 'Ingreso', 'aplica_en' => 'Aguinaldo',
            'concepto' => 'X', 'monto' => 100, 'fecha_inicio' => '2026-11-19',
        ])->assertStatus(422);

        $this->anticipo($this->fijo, 'Aguinaldo', 3000)->assertCreated();
        $this->assertSame(0, IngresoDeduccion::where('aplica_en', 'Planilla')->count());
        $this->assertSame('Unica', IngresoDeduccion::first()->frecuencia);
    }
}
