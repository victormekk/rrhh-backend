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
    Schema::create('empleados', function (Blueprint $table) {
        $table->id();
        $table->string('nombres', 30);
        $table->string('apellidos', 30);
        $table->string('cedula', 13)->unique();
        $table->string('rtn', 14)->nullable();
        $table->string('genero', 10);
        $table->date('fecha_nacimiento');
        $table->integer('edad');
        $table->string('estado_civil', 15);
        $table->integer('num_hijos')->default(0);
        $table->string('nacionalidad', 50);
        $table->string('residencia', 60);
        $table->string('telefono', 20);
        $table->string('contacto_emergencia', 50);
        $table->string('telefono_emergencia', 30);
        $table->string('correo', 50)->nullable();
        $table->string('tipo_sangre', 10);
        $table->string('foto_path')->nullable();
        $table->foreignId('id_info_laboral')->constrained('informacion_laboral');
        $table->foreignId('id_puesto')->constrained('puestos');
        $table->foreignId('id_departamento')->constrained('departamentos');
        $table->foreignId('id_usuario')->constrained('users');
        $table->timestamps();
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('empleados');
    }
};
