<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Adelanto de vacaciones: cuántos días de la solicitud se tomaron del próximo
// período vacacional (cuando el empleado no tenía saldo suficiente). Al cumplir
// su siguiente aniversario esos días ya quedan descontados y su saldo queda en cero.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('solicitudes_vacaciones', function (Blueprint $table) {
            $table->decimal('dias_adelantados', 5, 2)->default(0)->after('dias_tomados');
        });
    }

    public function down(): void
    {
        Schema::table('solicitudes_vacaciones', function (Blueprint $table) {
            $table->dropColumn('dias_adelantados');
        });
    }
};
