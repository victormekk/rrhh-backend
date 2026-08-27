<?php

namespace App\Http\Controllers;

use App\Models\LogSistema;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $request->validate([
            'email'    => 'required|email',
            'password' => 'required',
        ]);

        try {
            $user = User::where('email', $request->email)->first();
        } catch (QueryException $e) {
            return response()->json([
                'message' => 'No se pudo conectar a la base de datos. Verifique la conexión.',
            ], 503);
        }

        if (!$user || !Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['Correo o contraseña incorrectos.'],
            ]);
        }

        $token = $user->createToken('auth_token')->plainTextToken;

        LogSistema::create([
            'id_usuario'         => $user->id,
            'accion'             => 'login',
            'descripcion'        => "Inicio de sesión: {$user->name}.",
            'objeto_actualizado' => 'Sistema',
            'fecha'              => now()->toDateString(),
        ]);

        return response()->json([
            'user'  => $user,
            'token' => $token,
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Sesión cerrada correctamente.']);
    }

    public function me(Request $request)
    {
        return response()->json($request->user());
    }
}
