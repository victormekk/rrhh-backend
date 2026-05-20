<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('solicitudes_vacaciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('id_empleado')->constrained('empleados');
            $table->date('fecha_inicio');
            $table->date('fecha_fin');
            $table->decimal('dias_tomados', 5, 2);
            $table->text('observaciones')->nullable();
            $table->foreignId('id_usuario')->constrained('users');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('solicitudes_vacaciones');
    }
};
