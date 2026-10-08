<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

// Protege /api/asistencias/importar: el agente local (zkteco-agente/) no
// tiene sesion de usuario (corre desatendido en una PC del hotel), asi que
// en vez de auth:sanctum se valida un token fijo compartido, configurado en
// .env como ASISTENCIA_TOKEN y enviado por el agente en el header
// "X-Asistencia-Token".
class VerificaTokenAsistencia
{
    public function handle(Request $request, Closure $next)
    {
        $token = config('services.asistencia.token');

        abort_if(
            !$token || !hash_equals($token, (string) $request->header('X-Asistencia-Token')),
            401,
            'Token de asistencia inválido o no configurado.'
        );

        return $next($request);
    }
}
