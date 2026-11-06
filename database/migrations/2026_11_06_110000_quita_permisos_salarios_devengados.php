<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Salarios Devengados se quitó: duplicaba Estadística Laboral, que ahora filtra por
// departamento, tipo de contrato y estado. Se le quitan sus permisos a los roles.
return new class extends Migration
{
    private const QUITAR = ['salarios_devengados.ver', 'salarios_devengados.exportar'];

    public function up(): void
    {
        foreach (DB::table('roles')->get() as $rol) {
            $permisos = json_decode($rol->permisos, true) ?: [];
            $limpios  = array_values(array_diff($permisos, self::QUITAR));
            if (count($limpios) !== count($permisos)) {
                DB::table('roles')->where('id', $rol->id)->update(['permisos' => json_encode($limpios)]);
            }
        }
    }

    public function down(): void
    {
        // Sin vuelta atrás: el módulo ya no existe.
    }
};
