<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Permisos nuevos, dados a los roles que ya tenían el acceso relacionado para que
// no pierdan nada: "cumpleanos.exportar" (Excel de cumpleañeros) a quien ve Cumpleaños,
// y "dashboard.ver_jubilaciones" (próximos a jubilarse) a quien ve Empleados.
return new class extends Migration
{
    private const NUEVOS = [
        'cumpleanos.exportar'         => 'cumpleanos.ver',
        'dashboard.ver_jubilaciones'  => 'empleados.ver',
    ];

    public function up(): void
    {
        foreach (DB::table('roles')->where('es_admin', false)->get() as $rol) {
            $permisos = json_decode($rol->permisos, true) ?: [];
            foreach (self::NUEVOS as $nuevo => $siTiene) {
                if (in_array($siTiene, $permisos) && !in_array($nuevo, $permisos)) {
                    $permisos[] = $nuevo;
                }
            }
            DB::table('roles')->where('id', $rol->id)->update(['permisos' => json_encode($permisos)]);
        }
    }

    public function down(): void
    {
        foreach (DB::table('roles')->get() as $rol) {
            $permisos = array_values(array_diff(json_decode($rol->permisos, true) ?: [], array_keys(self::NUEVOS)));
            DB::table('roles')->where('id', $rol->id)->update(['permisos' => json_encode($permisos)]);
        }
    }
};
