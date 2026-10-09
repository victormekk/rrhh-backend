<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Historial laboral del empleado: cada ingreso, cese, reintegro y cambio de contrato.
    // informacion_laboral guarda solo el estado vigente; aquí queda todo lo anterior
    // (fechas de inicio previas, fechas y motivos de cese, si se liquidó).
    public function up(): void
    {
        Schema::create('historial_laboral', function (Blueprint $table) {
            $table->id();
            $table->foreignId('id_empleado')->constrained('empleados');
            $table->string('tipo_evento', 20);              // Ingreso, Cese, Reintegro, Cambio de contrato
            $table->date('fecha');                          // fecha en que ocurre el movimiento
            $table->string('tipo_contrato_anterior', 20)->nullable();
            $table->string('tipo_contrato_nuevo', 20)->nullable();
            $table->date('fecha_inicio_anterior')->nullable();
            $table->date('fecha_inicio_nueva')->nullable();
            $table->string('motivo_cese', 40)->nullable();  // solo en Cese
            $table->string('liquidacion', 10)->nullable();  // Sí, No, Pendiente (Cese y cambio con nueva fecha)
            $table->date('fecha_liquidacion')->nullable();  // cuándo se marcó como liquidado
            $table->string('observaciones', 500)->nullable(); // motivo del reintegro, detalle del cese, etc.
            $table->foreignId('id_usuario')->nullable()->constrained('users');
            $table->timestamps();

            $table->index(['id_empleado', 'fecha']);
            $table->index(['tipo_evento', 'fecha']);
        });

        // Historial inicial con lo que ya existe: el ingreso de cada empleado y,
        // si está inactivo, su cese con el motivo escrito a mano que tenía.
        $ahora = now();
        $empleados = DB::table('empleados as e')
            ->join('informacion_laboral as il', 'il.id', '=', 'e.id_info_laboral')
            ->select('e.id', 'e.created_at', 'il.tipo_contrato', 'il.fecha_inicio', 'il.fecha_cese',
                'il.motivo_cese', 'il.estado', 'il.updated_at', 'il.id_usuario')
            ->get();

        foreach ($empleados->chunk(200) as $lote) {
            $filas = [];
            foreach ($lote as $e) {
                $filas[] = [
                    'id_empleado'         => $e->id,
                    'tipo_evento'         => 'Ingreso',
                    'fecha'               => $e->fecha_inicio,
                    'tipo_contrato_nuevo' => $e->tipo_contrato,
                    'fecha_inicio_nueva'  => $e->fecha_inicio,
                    'observaciones'       => null,
                    'motivo_cese'         => null,
                    'liquidacion'         => null,
                    'id_usuario'          => $e->id_usuario,
                    'created_at'          => $e->created_at ?? $ahora,
                    'updated_at'          => $ahora,
                ];
                if ($e->estado === 'Inactivo') {
                    $filas[] = [
                        'id_empleado'         => $e->id,
                        'tipo_evento'         => 'Cese',
                        'fecha'               => $e->fecha_cese ?? substr((string) ($e->updated_at ?? $ahora), 0, 10),
                        'tipo_contrato_nuevo' => null,
                        'fecha_inicio_nueva'  => null,
                        'observaciones'       => $e->motivo_cese,
                        'motivo_cese'         => 'Sin especificar',
                        'liquidacion'         => null,
                        'id_usuario'          => null,
                        'created_at'          => $ahora,
                        'updated_at'          => $ahora,
                    ];
                }
            }
            DB::table('historial_laboral')->insert($filas);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('historial_laboral');
    }
};
