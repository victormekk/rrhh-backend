<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // solicitudes_vacaciones: fecha_inicio se filtra en calcularSaldo con SUM condicional
        Schema::table('solicitudes_vacaciones', function (Blueprint $table) {
            $table->index('fecha_inicio');
        });

        // log_sistema: se ordena y filtra por created_at y objeto_actualizado
        Schema::table('log_sistema', function (Blueprint $table) {
            $table->index('created_at');
            $table->index('objeto_actualizado');
        });

        // incidencias: se filtra por id_empleado y fecha (búsquedas y listados)
        Schema::table('incidencias', function (Blueprint $table) {
            $table->index('fecha_incidencia');
        });

        // detalle_planillas: cerrar() itera por id_empleado sobre el resultado del pluck
        Schema::table('detalle_planillas', function (Blueprint $table) {
            $table->index('nombre_planilla');
        });
    }

    public function down(): void
    {
        Schema::table('solicitudes_vacaciones', function (Blueprint $table) {
            $table->dropIndex(['fecha_inicio']);
        });
        Schema::table('log_sistema', function (Blueprint $table) {
            $table->dropIndex(['created_at']);
            $table->dropIndex(['objeto_actualizado']);
        });
        Schema::table('incidencias', function (Blueprint $table) {
            $table->dropIndex(['fecha_incidencia']);
        });
        Schema::table('detalle_planillas', function (Blueprint $table) {
            $table->dropIndex(['nombre_planilla']);
        });
    }
};
