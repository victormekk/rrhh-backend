<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Ingresos y deducciones que se repiten cada quincena o cada mes (préstamos,
    // adelantos de salario, bonos fijos...). Al crear una planilla se cargan solos
    // en la columna indicada, y al cerrarla cuentan como una cuota pagada.
    // Reemplaza a deducciones_cuotas, que nunca tuvo pantalla ni datos.
    public function up(): void
    {
        Schema::create('ingresos_deducciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('id_empleado')->constrained('empleados')->cascadeOnDelete();
            $table->string('tipo', 10);              // Ingreso | Deduccion
            $table->string('concepto', 50);
            $table->string('columna', 30);           // columna de detalle_planillas donde cae
            $table->decimal('monto', 10, 2);         // monto por planilla
            $table->string('frecuencia', 10);        // Quincenal | Mensual
            $table->unsignedTinyInteger('quincena_mes')->nullable(); // 1 o 2, solo si es Mensual
            $table->unsignedInteger('total_cuotas')->nullable();     // null = sin fin, hasta quitarla
            $table->date('fecha_inicio');            // primera planilla en que aplica
            $table->string('estado', 20)->default('Activo'); // Activo, Pausado, Completado, Cancelado
            $table->string('observacion', 200)->nullable();
            $table->foreignId('id_usuario')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['estado', 'id_empleado']);
        });

        // Qué ingreso/deducción se cargó en qué planilla. "aplicada" pasa a true al
        // cerrar la planilla, si el monto seguía en la celda (no se quitó a mano).
        Schema::create('ingresos_deducciones_aplicadas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('id_ingreso_deduccion')->constrained('ingresos_deducciones')->cascadeOnDelete();
            $table->foreignId('id_cabecera_planilla')->constrained('cabecera_planillas')->cascadeOnDelete();
            $table->foreignId('id_detalle_planilla')->constrained('detalle_planillas')->cascadeOnDelete();
            $table->string('columna', 30);   // donde quedó (puede diferir de la del registro, ver servicio)
            $table->decimal('monto', 10, 2);
            $table->boolean('aplicada')->default(false);
            $table->timestamps();

            $table->unique(['id_ingreso_deduccion', 'id_cabecera_planilla'], 'ing_ded_aplicada_unica');
        });

        Schema::dropIfExists('deducciones_cuotas');
    }

    public function down(): void
    {
        Schema::dropIfExists('ingresos_deducciones_aplicadas');
        Schema::dropIfExists('ingresos_deducciones');

        Schema::create('deducciones_cuotas', function (Blueprint $table) {
            $table->id();
            $table->string('nombre_deduccion', 50);
            $table->decimal('monto', 10, 2);
            $table->integer('total_cuotas');
            $table->integer('cuotas_aplicadas')->default(0);
            $table->string('estado', 20)->default('Activo');
            $table->date('fecha');
            $table->foreignId('id_empleado')->constrained('empleados');
            $table->foreignId('id_usuario')->constrained('users');
            $table->timestamps();
        });
    }
};
