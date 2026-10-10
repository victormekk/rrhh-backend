<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Evento "Cambio de puesto": cargo y/o departamento anterior y nuevo. Se guardan los
    // nombres (no los ids) para que el historial no cambie si luego se renombra un cargo
    // o departamento.
    public function up(): void
    {
        Schema::table('historial_laboral', function (Blueprint $table) {
            $table->string('cargo_anterior', 50)->nullable()->after('fecha_inicio_nueva');
            $table->string('cargo_nuevo', 50)->nullable()->after('cargo_anterior');
            $table->string('departamento_anterior', 50)->nullable()->after('cargo_nuevo');
            $table->string('departamento_nuevo', 50)->nullable()->after('departamento_anterior');
        });
    }

    public function down(): void
    {
        Schema::table('historial_laboral', function (Blueprint $table) {
            $table->dropColumn(['cargo_anterior', 'cargo_nuevo', 'departamento_anterior', 'departamento_nuevo']);
        });
    }
};
