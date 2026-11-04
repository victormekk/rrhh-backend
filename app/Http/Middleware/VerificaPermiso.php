<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

// Uso en rutas: ->middleware('permiso:empleados.ver,vacaciones.ver')
// Deja pasar si el usuario tiene al menos uno de los permisos (ver config/permisos.php).
class VerificaPermiso
{
    public function handle(Request $request, Closure $next, string ...$permisos)
    {
        if (!$request->user()?->tienePermiso(...$permisos)) {
            return response()->json(['message' => 'No tiene permiso para realizar esta acción.'], 403);
        }

        return $next($request);
    }
}
