<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // DNI hasta 30 caracteres con guiones: el carnet de residencia de extranjeros
    // no tiene el formato de 13 dígitos de la identidad hondureña.
    public function up(): void
    {
        Schema::table('empleados', function (Blueprint $table) {
            $table->string('cedula', 30)->change();
        });
    }

    public function down(): void
    {
        Schema::table('empleados', function (Blueprint $table) {
            $table->string('cedula', 13)->change();
        });
    }
};
