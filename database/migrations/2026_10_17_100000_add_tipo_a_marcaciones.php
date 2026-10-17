<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Tipo de marca que registra el reloj (Entrada, Salida...), cargado desde
    // el CSV de "Cargar Marcas". Sirve para detectar marcas incompletas.
    public function up(): void
    {
        Schema::table('marcaciones', function (Blueprint $table) {
            $table->string('tipo', 20)->nullable()->after('hora');
        });
    }

    public function down(): void
    {
        Schema::table('marcaciones', function (Blueprint $table) {
            $table->dropColumn('tipo');
        });
    }
};
