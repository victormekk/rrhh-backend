<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Marcaciones crudas importadas desde relojes biometricos ZKTeco (via el
    // agente local, ver zkteco-agente/). Una fila por cada marcaje (entrada o
    // salida); "1 dia trabajado" se deriva contando dias con 2+ marcajes,
    // no se calcula ni se guarda aqui - eso vive en PlanillaController.
    public function up(): void
    {
        Schema::create('marcaciones', function (Blueprint $table) {
            $table->id();
            $table->string('codigo_biometrico', 20);
            $table->foreignId('id_empleado')->nullable()->constrained('empleados')->nullOnDelete();
            $table->date('fecha');
            $table->time('hora');
            $table->string('device_ip', 45)->nullable();
            $table->timestamps();

            $table->unique(['codigo_biometrico', 'fecha', 'hora'], 'marcaciones_unicas');
            $table->index(['id_empleado', 'fecha']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marcaciones');
    }
};
