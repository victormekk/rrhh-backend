<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Datos FICTICIOS para probar el promedio de días de aguinaldo/catorceavo de extras:
// 20 empleados extras (cédula "PRUEBA-xxxxx") y 2 años de planillas de extras
// ("PRUEBA Extras ..."). Con --borrar se elimina todo lo que se generó.
// Nunca corre en producción: borrar antes de exportar la base a Railway.
class DatosPruebaExtras extends Command
{
    protected $signature = 'pruebas:extras {--borrar : Elimina los empleados y planillas de prueba}';

    protected $description = 'Crea (o borra con --borrar) extras y planillas ficticias para probar el promedio de días';

    private const PREFIJO_CEDULA   = 'PRUEBA-';
    private const PREFIJO_PLANILLA = 'PRUEBA ';

    private const NOMBRES_FEMENINOS = ['ANA', 'MARIA', 'KAREN', 'DIANA', 'SANDRA', 'GABRIELA', 'LESLY', 'MIRNA', 'ROSA', 'BRENDA'];

    // [nombres, apellidos, departamento, cargo, trabaja todos los días]
    private const EMPLEADOS = [
        ['ANA LUCIA', 'MEJIA PAZ', 'ANIMACIÓN', 'ANIMADOR', true],
        ['CARLOS EDUARDO', 'RIVERA LOPEZ', 'ANIMACIÓN', 'ANIMADOR', true],
        ['JOSE MANUEL', 'CASTRO DIAZ', 'BARES', 'BARTENDER', false],
        ['MARIA FERNANDA', 'ZELAYA CRUZ', 'BARES', 'BARTENDER', false],
        ['LUIS ALBERTO', 'MARTINEZ SOTO', 'BARES', 'BARTENDER', false],
        ['KAREN JULISSA', 'FLORES REYES', 'COCINA', 'COCINERO', false],
        ['OSCAR DAVID', 'HERNANDEZ ORTIZ', 'COCINA', 'COCINERO', false],
        ['DIANA PATRICIA', 'GOMEZ VARELA', 'COCINA', 'COCINERO', false],
        ['MARIO ANTONIO', 'PAZ MOLINA', 'COCINA', 'COCINERO', false],
        ['SANDRA YAMILETH', 'LOPEZ BANEGAS', 'RESTAURANTE', 'MESERO', false],
        ['KEVIN JOSUE', 'SUAZO MEJIA', 'RESTAURANTE', 'MESERO', false],
        ['GABRIELA MARIA', 'ORELLANA PINEDA', 'RESTAURANTE', 'MESERO', false],
        ['ERICK FERNANDO', 'CABRERA DUARTE', 'RESTAURANTE', 'MESERO', false],
        ['LESLY CAROLINA', 'AGUILAR RAMOS', 'PISOS', 'CAMARERA', false],
        ['MIRNA ESTELA', 'VASQUEZ CARDONA', 'PISOS', 'CAMARERA', false],
        ['ROSA ELENA', 'MURILLO SANTOS', 'PISOS', 'CAMARERA', false],
        ['WILMER JOEL', 'CRUZ ALVARADO', 'JARDINERÍA', 'JARDINERO', false],
        ['NELSON RAUL', 'ESPINAL TORRES', 'JARDINERÍA', 'JARDINERO', false],
        ['BRENDA JOHANA', 'ROSALES MEZA', 'PISOS', 'CAMARERA', false],
        ['JORGE LUIS', 'PADILLA NUÑEZ', 'BARES', 'BARTENDER', false],
    ];

    public function handle(): int
    {
        if (app()->environment('production')) {
            $this->error('Este comando no se ejecuta en producción.');
            return self::FAILURE;
        }

        return $this->option('borrar') ? $this->borrar() : $this->crear();
    }

    private function crear(): int
    {
        if (DB::table('empleados')->where('cedula', 'like', self::PREFIJO_CEDULA . '%')->exists()) {
            $this->warn('Ya existen datos de prueba. Use --borrar primero si quiere regenerarlos.');
            return self::FAILURE;
        }

        $usuario = DB::table('users')->where('rol', 'admin')->value('id');
        $minimo  = (float) (DB::table('campos_variables')->where('nombre_campo', 'salario_minimo')->value('monto') ?? 16317.60);
        $diario  = round($minimo / 30, 2);
        $hoy     = Carbon::today();
        mt_srand(2026); // mismos datos en cada ejecución

        DB::transaction(function () use ($usuario, $minimo, $diario, $hoy) {
            $empleados = [];
            $total     = count(self::EMPLEADOS);

            foreach (self::EMPLEADOS as $i => [$nombres, $apellidos, $depto, $cargo, $siempre]) {
                // Fechas de inicio repartidas entre hace 2 años y hace 3 meses.
                $inicio = $hoy->copy()->subYears(2)->addDays((int) round($i * (640 / ($total - 1))));

                // 2 de cada 3 cobran por banco (con cuenta) para probar Bancos y Cheques.
                $cuenta = $i % 3 === 2 ? null : '99' . str_pad($i + 1, 10, '0', STR_PAD_LEFT);

                $idInfo = DB::table('informacion_laboral')->insertGetId([
                    'tipo_contrato' => 'Extra', 'fecha_inicio' => $inicio->toDateString(), 'estado' => 'Activo',
                    'moneda' => 'Lempiras', 'forma_de_pago' => $cuenta ? 'Transferencia' : 'Cheque',
                    'num_cuenta' => $cuenta, 'id_banco' => $cuenta ? DB::table('bancos')->value('id') : null,
                    'salario_base' => $minimo, 'salario_quincenal' => round($minimo / 2, 2),
                    'salario_diario' => $diario, 'salario_por_hora' => round($minimo / 30 / 8, 2),
                    'usa_salario_minimo' => true, 'sin_promedio_dias' => $siempre,
                    'id_usuario' => $usuario, 'created_at' => now(), 'updated_at' => now(),
                ]);

                $idDepto = DB::table('departamentos')->where('nombre', $depto)->value('id');
                $idCargo = DB::table('cargos')->where('nombre', $cargo)->value('id');

                $id = DB::table('empleados')->insertGetId([
                    'nombres' => $nombres, 'apellidos' => $apellidos,
                    'cedula' => self::PREFIJO_CEDULA . str_pad($i + 1, 5, '0', STR_PAD_LEFT),
                    'genero' => in_array(strtok($nombres, ' '), self::NOMBRES_FEMENINOS) ? 'Femenino' : 'Masculino', 'fecha_nacimiento' => '1995-01-01', 'edad' => 31,
                    'estado_civil' => 'Soltero/a', 'num_hijos' => 0, 'nacionalidad' => 'HONDUREÑO',
                    'residencia' => 'DATO DE PRUEBA', 'telefono' => '0000-0000',
                    'contacto_emergencia' => 'DATO DE PRUEBA', 'parentesco_emergencia' => 'OTRO',
                    'telefono_emergencia' => '0000-0000', 'tipo_sangre' => 'O+',
                    'id_info_laboral' => $idInfo, 'id_cargo' => $idCargo, 'id_departamento' => $idDepto,
                    'id_usuario' => $usuario, 'created_at' => now(), 'updated_at' => now(),
                ]);

                // Nivel de trabajo típico por quincena (los que trabajan siempre: 15).
                $empleados[] = ['id' => $id, 'inicio' => $inicio, 'depto' => $depto,
                    'siempre' => $siempre, 'nivel' => mt_rand(6, 14)];
            }

            // 2 años de quincenas (día 15 y fin de mes) hasta el último cierre de quincena.
            $fecha  = $hoy->copy()->subYears(2)->startOfMonth()->day(15);
            $limite = $hoy->day >= 15 ? $hoy->copy()->day(15) : $hoy->copy()->subMonthNoOverflow()->endOfMonth();
            $planillas = 0;

            while ($fecha->lte($limite)) {
                $nombre = self::PREFIJO_PLANILLA . 'Extras ' . $fecha->day . ' de '
                    . ucfirst($fecha->locale('es')->monthName) . ' ' . $fecha->year;

                $idCab = DB::table('cabecera_planillas')->insertGetId([
                    'nombre_planilla' => $nombre, 'tipo_planilla' => 'Extras', 'estado' => 'Cerrado',
                    'fecha_generada' => $fecha->toDateString(), 'id_usuario' => $usuario,
                    'created_at' => now(), 'updated_at' => now(),
                ]);

                foreach ($empleados as $e) {
                    if ($e['inicio']->gt($fecha)) {
                        continue;
                    }
                    // Algunas quincenas pasan de 15 por horas extra (16-20): prueban el tope.
                    $dias = $e['siempre'] ? 15
                        : (mt_rand(1, 100) <= 8 ? mt_rand(16, 20) : max(0, min(15, $e['nivel'] + mt_rand(-5, 4))));

                    DB::table('detalle_planillas')->insert([
                        'id_cabecera_planilla' => $idCab, 'id_empleado' => $e['id'], 'nombre_planilla' => $nombre,
                        'departamento' => $e['depto'], 'tipo_planilla' => 'Extras', 'dias_trabajados' => $dias,
                        'salario_diario' => $diario, 'salario_base' => round($diario * $dias, 2),
                        'salario_neto' => round($diario * $dias, 2), 'fecha_generada' => $fecha->toDateString(),
                        'id_usuario' => $usuario, 'created_at' => now(), 'updated_at' => now(),
                    ]);
                }

                $planillas++;
                $fecha = $fecha->day === 15 ? $fecha->copy()->endOfMonth()->startOfDay() : $fecha->copy()->addMonthNoOverflow()->day(15);
            }

            $this->info(count($empleados) . " empleados extras y $planillas planillas de prueba creados.");
        });

        $this->line('Para eliminarlos: php artisan pruebas:extras --borrar');

        return self::SUCCESS;
    }

    private function borrar(): int
    {
        $ids = DB::table('empleados')->where('cedula', 'like', self::PREFIJO_CEDULA . '%')->pluck('id');
        $infos = DB::table('empleados')->whereIn('id', $ids)->pluck('id_info_laboral');

        DB::transaction(function () use ($ids, $infos) {
            // Todo lo que apunte a los empleados de prueba (planillas, aguinaldos, etc.).
            foreach (Schema::getTableListing(schemaQualified: false) as $tabla) {
                if ($tabla !== 'empleados' && Schema::hasColumn($tabla, 'id_empleado')) {
                    DB::table($tabla)->whereIn('id_empleado', $ids)->delete();
                }
            }

            $cabeceras = DB::table('cabecera_planillas')->where('nombre_planilla', 'like', self::PREFIJO_PLANILLA . '%')->pluck('id');
            DB::table('detalle_planillas')->whereIn('id_cabecera_planilla', $cabeceras)->delete();
            DB::table('cabecera_planillas')->whereIn('id', $cabeceras)->delete();

            DB::table('empleados')->whereIn('id', $ids)->delete();
            DB::table('informacion_laboral')->whereIn('id', $infos)->delete();

            $this->info("Eliminados {$ids->count()} empleados y {$cabeceras->count()} planillas de prueba.");
        });

        return self::SUCCESS;
    }
}
