<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('detalle_planillas', function (Blueprint $table) {
            // Recargo de las horas extra en %: 0 (sin recargo), 25 (diurna), 50 (nocturna) o 75.
            // Se guarda en cada fila para que una planilla cerrada no cambie si cambian las reglas.
            $table->unsignedTinyInteger('recargo_horas_extras')->default(0)->after('horas_extras');
        });
    }

    public function down(): void
    {
        Schema::table('detalle_planillas', function (Blueprint $table) {
            $table->dropColumn('recargo_horas_extras');
        });
    }
};
