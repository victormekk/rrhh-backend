<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

// Si un admin deshabilita a un usuario mientras tiene la sesión abierta, en su
// siguiente petición se le revoca el token y se responde 401 con
// codigo "cuenta_deshabilitada" para que el frontend muestre el aviso en el login.
class VerificaUsuarioActivo
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        if ($user && !$user->activo) {
            $user->tokens()->delete();

            return response()->json([
                'message' => 'Su cuenta fue deshabilitada. Contacte al administrador.',
                'codigo'  => 'cuenta_deshabilitada',
            ], 401);
        }

        return $next($request);
    }
}
