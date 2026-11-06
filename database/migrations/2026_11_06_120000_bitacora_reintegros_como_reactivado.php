<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Los reintegros de empleados se registran en la bitácora como "reactivado".
// Corrige los que quedaron como "editado".
return new class extends Migration
{
    public function up(): void
    {
        DB::table('bitacora')
            ->where('accion', 'editado')
            ->where('objeto_actualizado', 'Empleados')
            ->where('descripcion', 'like', '%reintegrado como%')
            ->update(['accion' => 'reactivado']);
    }

    public function down(): void
    {
        DB::table('bitacora')
            ->where('accion', 'reactivado')
            ->update(['accion' => 'editado']);
    }
};
