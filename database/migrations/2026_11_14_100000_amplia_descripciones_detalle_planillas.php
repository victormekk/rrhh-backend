<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Las descripciones concatenan todos los conceptos aplicados ("Concepto: monto, ...")
// y superaban los 100 caracteres, haciendo fallar la generación de planillas.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('detalle_planillas', function (Blueprint $table) {
            $table->text('desc_ingresos')->nullable()->change();
            $table->text('desc_otras_deducciones')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('detalle_planillas', function (Blueprint $table) {
            $table->string('desc_ingresos', 100)->nullable()->change();
            $table->string('desc_otras_deducciones', 100)->nullable()->change();
        });
    }
};
