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
    Schema::create('otros_ingresos', function (Blueprint $table) {
        $table->id();
        $table->string('descripcion')->nullable();
        $table->decimal('monto', 10, 2);
        $table->string('nombre_planilla', 50)->nullable();
        $table->date('fecha');
        $table->foreignId('id_empleado')->constrained('empleados');
        $table->softDeletes(); // Para el campo Deleted del sistema actual
        $table->timestamps();
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('otros_ingresos');
    }
};
