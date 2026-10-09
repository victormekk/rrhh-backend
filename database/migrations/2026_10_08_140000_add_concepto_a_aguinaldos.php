<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Aguinaldo (décimo tercer mes, diciembre) y Catorceavo (décimo cuarto, junio)
    // comparten fórmulas y tablas; "concepto" los separa en planillas independientes.
    public function up(): void
    {
        foreach (['aguinaldo_fijos', 'aguinaldo_extras'] as $tabla) {
            Schema::table($tabla, function (Blueprint $table) {
                $table->string('concepto', 12)->default('Aguinaldo')->after('tipo_aguinaldo');
            });

            DB::table($tabla)->where('nombre_aguinaldo', 'like', '%catorce%')->update(['concepto' => 'Catorceavo']);
        }
    }

    public function down(): void
    {
        foreach (['aguinaldo_fijos', 'aguinaldo_extras'] as $tabla) {
            Schema::table($tabla, function (Blueprint $table) {
                $table->dropColumn('concepto');
            });
        }
    }
};
