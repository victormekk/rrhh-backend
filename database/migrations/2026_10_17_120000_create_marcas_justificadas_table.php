<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Días con marca incompleta (solo entrada o solo salida) que RRHH revisó
    // con el jefe y justificó (falla de energía, olvido de marcar...). Un día
    // justificado cuenta como trabajado al generar la planilla.
    public function up(): void
    {
        Schema::create('marcas_justificadas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('id_empleado')->constrained('empleados')->cascadeOnDelete();
            $table->date('fecha');
            $table->string('motivo', 255);
            $table->foreignId('id_usuario')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['id_empleado', 'fecha']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marcas_justificadas');
    }
};
