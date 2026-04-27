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
    Schema::create('informacion_laboral', function (Blueprint $table) {
        $table->id();
        $table->string('tipo_contrato', 20); // Fijo, Extra, Especial
        $table->date('fecha_inicio');
        $table->date('fecha_cese')->nullable();
        $table->string('motivo_cese', 300)->nullable();
        $table->string('estado', 20)->default('Activo'); // Activo, Inactivo
        $table->string('moneda', 20)->default('Lempiras');
        $table->string('forma_de_pago', 50); // Efectivo, Transferencia
        $table->string('num_cuenta', 25)->nullable();
        $table->decimal('salario_base', 10, 2);
        $table->decimal('salario_quincenal', 10, 2);
        $table->decimal('salario_diario', 10, 2);
        $table->decimal('salario_por_hora', 10, 2);
        $table->boolean('usa_salario_minimo')->default(false);
        $table->foreignId('id_banco')->nullable()->constrained('bancos');
        $table->foreignId('id_usuario')->constrained('users');
        $table->timestamps();
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('informacion_laboral');
    }
};
