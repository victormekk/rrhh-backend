<?php

namespace App\Traits;

use App\Models\LogSistema;

trait LogsActividad
{
    private function logActividad(
        string $accion,
        string $modulo,
        string $descripcion,
        ?int   $idObjeto = null
    ): void {
        try {
            LogSistema::create([
                'id_usuario'            => auth()->id(),
                'accion'                => $accion,
                'descripcion'           => $descripcion,
                'id_objeto_involucrado' => $idObjeto,
                'objeto_actualizado'    => $modulo,
                'fecha'                 => now()->toDateString(),
            ]);
        } catch (\Throwable) {
            // El log nunca debe romper la operación principal
        }
    }
}
