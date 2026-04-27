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
    Schema::create('vacaciones', function (Blueprint $table) {
        $table->id();
        $table->decimal('primer_anio', 5, 2)->default(10);  // 1 año = 10 días
        $table->decimal('segundo_anio', 5, 2)->default(12); // 2 años = 12 días
        $table->decimal('tercer_anio', 5, 2)->default(15);  // 3 años = 15 días
        $table->decimal('cuarto_anio_adelante', 5, 2)->default(18); // 4+ años = 18 días
        $table->foreignId('id_usuario')->constrained('users');
        $table->timestamps();
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vacaciones');
    }
};
