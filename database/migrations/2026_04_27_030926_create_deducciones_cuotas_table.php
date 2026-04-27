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
    Schema::create('deducciones_cuotas', function (Blueprint $table) {
        $table->id();
        $table->string('nombre_deduccion', 50);
        $table->decimal('monto', 10, 2);
        $table->integer('total_cuotas'); // Cuántas quincenas se descuenta
        $table->integer('cuotas_aplicadas')->default(0); // Cuántas van aplicadas
        $table->string('estado', 20)->default('Activo'); // Activo, Completado
        $table->date('fecha');
        $table->foreignId('id_empleado')->constrained('empleados');
        $table->foreignId('id_usuario')->constrained('users');
        $table->timestamps();
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('deducciones_cuotas');
    }
};
