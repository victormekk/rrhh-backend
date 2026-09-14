<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Se usa SQL crudo para renombrar columnas (CHANGE) en vez de
     * Schema::renameColumn(), porque el proyecto no tiene doctrine/dbal
     * instalado (requerido por Laravel 10 para esa operacion). MySQL
     * actualiza automaticamente las llaves foraneas al renombrar la
     * tabla/columna referenciada, asi que los datos y relaciones se
     * conservan intactos.
     */
    public function up(): void
    {
        Schema::rename('puestos', 'cargos');

        DB::statement('ALTER TABLE empleados CHANGE id_puesto id_cargo BIGINT UNSIGNED NOT NULL');
        DB::statement('ALTER TABLE aguinaldo_fijos CHANGE puesto cargo VARCHAR(50) NULL');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE aguinaldo_fijos CHANGE cargo puesto VARCHAR(50) NULL');
        DB::statement('ALTER TABLE empleados CHANGE id_cargo id_puesto BIGINT UNSIGNED NOT NULL');

        Schema::rename('cargos', 'puestos');
    }
};
