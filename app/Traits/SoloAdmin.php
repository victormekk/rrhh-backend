<?php

namespace App\Traits;

use Illuminate\Http\Request;

trait SoloAdmin
{
    private function soloAdmin(Request $request): void
    {
        if ($request->user()->rol !== 'admin') {
            abort(response()->json(['message' => 'Acción reservada para administradores.'], 403));
        }
    }
}
