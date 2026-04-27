<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
{
    Schema::create('detalle_planillas', function (Blueprint $table) {
        $table->id();
        $table->foreignId('id_cabecera_planilla')->constrained('cabecera_planillas');
        $table->foreignId('id_empleado')->constrained('empleados');
        $table->string('nombre_planilla', 50);
        $table->string('departamento', 50);
        $table->string('tipo_planilla', 20); // Fijos, Extras, Especial
        $table->integer('dias_trabajados')->default(15);
        $table->decimal('salario_diario', 10, 2);
        $table->decimal('salario_base', 10, 2);
        $table->string('desc_ingresos', 100)->nullable();
        $table->decimal('otros_ingresos', 10, 2)->default(0);
        $table->decimal('ihss', 10, 2)->default(0);
        $table->decimal('retencion_ahorro', 10, 2)->default(0);
        $table->decimal('crefisa', 10, 2)->default(0);
        $table->decimal('isr', 10, 2)->default(0); // Para especiales
        $table->decimal('transporte', 10, 2)->default(0);
        $table->decimal('radios', 10, 2)->default(0);
        $table->decimal('uniforme', 10, 2)->default(0);
        $table->decimal('garden', 10, 2)->default(0);
        $table->string('desc_otras_deducciones', 100)->nullable();
        $table->decimal('otras_deducciones', 10, 2)->default(0);
        $table->decimal('deduccion_neta', 10, 2)->default(0);
        $table->decimal('salario_neto', 10, 2)->default(0);
        $table->string('cuenta_banco', 50)->nullable();
        $table->date('fecha_generada');
        $table->foreignId('id_usuario')->constrained('users');
        $table->timestamps();
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('detalle_planillas');
    }
};
