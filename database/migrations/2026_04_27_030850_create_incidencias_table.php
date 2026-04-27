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
    Schema::create('incidencias', function (Blueprint $table) {
        $table->id();
        $table->date('fecha_incidencia');
        $table->string('titulo', 50);
        $table->string('descripcion', 300);
        $table->string('grado', 20); // Leve, Moderada, Grave
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
        Schema::dropIfExists('incidencias');
    }
};
