<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Las bajas de empleados nunca borran nada: en la bitácora se registran como
// "desactivado". Corrige las que quedaron como "eliminado".
return new class extends Migration
{
    public function up(): void
    {
        DB::table('bitacora')
            ->where('accion', 'eliminado')
            ->where('objeto_actualizado', 'Empleados')
            ->where('descripcion', 'like', '%dado de baja%')
            ->update(['accion' => 'desactivado']);
    }

    public function down(): void
    {
        DB::table('bitacora')
            ->where('accion', 'desactivado')
            ->update(['accion' => 'eliminado']);
    }
};
