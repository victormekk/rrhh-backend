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
    Schema::create('log_sistema', function (Blueprint $table) {
        $table->id();
        $table->string('accion', 50);
        $table->integer('id_objeto_involucrado')->nullable();
        $table->string('objeto_actualizado', 50)->nullable();
        $table->date('fecha');
        $table->foreignId('id_usuario')->constrained('users');
        $table->timestamps();
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('log_sistema');
    }
};
