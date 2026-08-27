<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('detalle_planillas', function (Blueprint $table) {
            $table->decimal('horas_extras', 6, 2)->default(0)->after('otros_ingresos');
            $table->decimal('monto_horas_extras', 10, 2)->default(0)->after('horas_extras');
            $table->decimal('i_vecinal', 10, 2)->default(0)->after('garden');
        });
    }

    public function down(): void
    {
        Schema::table('detalle_planillas', function (Blueprint $table) {
            $table->dropColumn(['horas_extras', 'monto_horas_extras', 'i_vecinal']);
        });
    }
};
