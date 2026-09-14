<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Correlativo de 5 digitos para cada PDF generado (Voucher, Constancia
    // Laboral, Incidencia, Vacacion, Planilla, Aguinaldo, Estadistica Laboral,
    // Log del Sistema). Cada "tipo" lleva su propia secuencia, empezando en 1
    // (se muestra con str_pad a 5 digitos: 00001, 00002...). Independiente de
    // los ids internos de otras tablas, que crecen por datos ajenos al documento
    // (ej. filas de detalle por cada empleado de cada planilla).
    public function up(): void
    {
        Schema::create('documentos_generados', function (Blueprint $table) {
            $table->id();
            $table->string('tipo', 40);
            $table->unsignedInteger('correlativo');
            $table->unsignedBigInteger('referencia_id')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['tipo', 'correlativo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documentos_generados');
    }
};
