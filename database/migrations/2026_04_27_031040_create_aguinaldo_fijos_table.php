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
    Schema::create('aguinaldo_fijos', function (Blueprint $table) {
        $table->id();
        $table->string('nombre_aguinaldo', 50);
        $table->string('departamento', 20)->nullable();
        $table->string('nombres', 50)->nullable();
        $table->string('apellidos', 50)->nullable();
        $table->string('cuenta', 50)->nullable();
        $table->date('fecha_inicio')->nullable();
        $table->decimal('salario_base', 10, 2)->nullable();
        $table->integer('dias_trabajados')->nullable();
        $table->decimal('anticipo', 10, 2)->default(0);
        $table->decimal('total_aguinaldo', 10, 2)->nullable();
        $table->date('fecha_generada')->nullable();
        $table->string('estado', 50)->nullable();
        $table->string('tipo_aguinaldo', 50)->nullable();
        $table->foreignId('id_empleado')->constrained('empleados');
        $table->foreignId('id_info_laboral')->constrained('informacion_laboral');
        $table->foreignId('id_departamento')->constrained('departamentos');
        $table->timestamps();
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('aguinaldo_fijos');
    }
};
