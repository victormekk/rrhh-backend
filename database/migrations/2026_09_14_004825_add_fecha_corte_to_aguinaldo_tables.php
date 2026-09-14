<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('aguinaldo_fijos', function (Blueprint $table) {
            $table->date('fecha_corte')->nullable()->after('fecha_generada');
        });

        Schema::table('aguinaldo_extras', function (Blueprint $table) {
            $table->date('fecha_corte')->nullable()->after('fecha_generada');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('aguinaldo_fijos', function (Blueprint $table) {
            $table->dropColumn('fecha_corte');
        });

        Schema::table('aguinaldo_extras', function (Blueprint $table) {
            $table->dropColumn('fecha_corte');
        });
    }
};
