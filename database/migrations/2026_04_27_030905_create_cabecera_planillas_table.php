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
    Schema::create('cabecera_planillas', function (Blueprint $table) {
        $table->id();
        $table->string('nombre_planilla', 50);
        $table->string('tipo_planilla', 20); // Fijos, Extras, Especial
        $table->string('estado', 20)->default('Activo'); // Activo, Cerrado
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
        Schema::dropIfExists('cabecera_planillas');
    }
};
