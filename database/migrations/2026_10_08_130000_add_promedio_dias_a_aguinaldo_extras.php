<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Extras que trabajan todos los días (ej. Animación): en aguinaldo/catorceavo
        // no se les aplica el promedio de días, solo la antigüedad.
        Schema::table('informacion_laboral', function (Blueprint $table) {
            $table->boolean('sin_promedio_dias')->default(false)->after('usa_salario_minimo');
        });

        Schema::table('aguinaldo_extras', function (Blueprint $table) {
            // Antigüedad pasa de monto a "días de 30" con decimales (igual que el Excel:
            // días al corte / 360 * 30); 4 decimales para no desviar el total.
            $table->decimal('antiguedad', 8, 4)->nullable()->change();
            $table->decimal('promedio_dias', 7, 3)->nullable()->after('dias_promedio');
            $table->decimal('meses_promedio', 4, 1)->nullable()->after('promedio_dias');
            $table->boolean('sin_promedio')->default(false)->after('meses_promedio');
            $table->date('periodo_desde')->nullable()->after('fecha_corte');
            $table->date('periodo_hasta')->nullable()->after('periodo_desde');
        });
    }

    public function down(): void
    {
        Schema::table('aguinaldo_extras', function (Blueprint $table) {
            $table->dropColumn(['promedio_dias', 'meses_promedio', 'sin_promedio', 'periodo_desde', 'periodo_hasta']);
            $table->decimal('antiguedad', 10, 2)->nullable()->change();
        });

        Schema::table('informacion_laboral', function (Blueprint $table) {
            $table->dropColumn('sin_promedio_dias');
        });
    }
};
