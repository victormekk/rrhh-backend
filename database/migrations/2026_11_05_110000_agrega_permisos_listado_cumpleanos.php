<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Nuevo módulo Reportes → Listado de Cumpleaños. Sus permisos se dan a los roles que
// ya veían/exportaban Cumpleaños, para que no tengan que asignarse a mano.
return new class extends Migration
{
    private const NUEVOS = [
        'listado_cumpleanos.ver'      => 'cumpleanos.ver',
        'listado_cumpleanos.exportar' => 'cumpleanos.exportar',
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
