<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Anticipos de aguinaldo/catorceavo: una deducción que no va a las planillas
    // quincenales sino a la columna "Anticipo" de la planilla especial del concepto
    // elegido, completa y una sola vez.
    public function up(): void
    {
        Schema::table('ingresos_deducciones', function (Blueprint $table) {
            $table->string('aplica_en', 12)->default('Planilla')->after('tipo'); // Planilla | Aguinaldo | Catorceavo
        });

        // En un aguinaldo no hay cabecera/detalle de planilla: se identifica por el
        // nombre del lote (y el empleado del registro).
        Schema::table('ingresos_deducciones_aplicadas', function (Blueprint $table) {
            $table->unsignedBigInteger('id_cabecera_planilla')->nullable()->change();
            $table->unsignedBigInteger('id_detalle_planilla')->nullable()->change();
            $table->string('nombre_aguinaldo', 50)->nullable()->after('id_detalle_planilla');
            $table->index('nombre_aguinaldo');
        });
    }

    public function down(): void
    {
        Schema::table('ingresos_deducciones_aplicadas', function (Blueprint $table) {
            $table->dropIndex(['nombre_aguinaldo']);
            $table->dropColumn('nombre_aguinaldo');
        });

        Schema::table('ingresos_deducciones', function (Blueprint $table) {
            $table->dropColumn('aplica_en');
        });
    }
};
