<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Traits\LogsActividad;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class UsuarioController extends Controller
{
    use LogsActividad;

    private function soloAdmin(Request $request)
    {
        if ($request->user()->rol !== 'admin') {
            abort(response()->json(['message' => 'Acción reservada para administradores.'], 403));
        }
    }

    public function index(Request $request)
    {
        $this->soloAdmin($request);

        return User::select('id', 'name', 'email', 'rol', 'created_at')
            ->when($request->search, fn($q, $s) =>
                $q->where('name', 'like', "%$s%")
                  ->orWhere('email', 'like', "%$s%")
            )
            ->orderBy('name')
            ->paginate(20);
    }

    public function store(Request $request)
    {
        $this->soloAdmin($request);

        $data = $request->validate([
            'name'     => 'required|string|max:100',
            'email'    => 'required|email|max:100|unique:users,email',
            'password' => 'required|string|min:8',
            'rol'      => 'required|in:admin,rrhh',
        ]);

        $user = User::create([
            'name'     => $data['name'],
            'email'    => $data['email'],
            'password' => Hash::make($data['password']),
            'rol'      => $data['rol'],
        ]);

        $this->logActividad('creado', 'Usuarios', "Usuario {$user->name} ({$user->email}) creado con rol {$user->rol}.", $user->id);

        return response()->json($user->only(['id', 'name', 'email', 'rol', 'created_at']), 201);
    }

    public function update(Request $request, $id)
    {
        $this->soloAdmin($request);

        $user = User::findOrFail($id);

        $data = $request->validate([
            'name'     => 'required|string|max:100',
            'email'    => "required|email|max:100|unique:users,email,{$id}",
            'rol'      => 'required|in:admin,rrhh',
            'password' => 'nullable|string|min:8',
        ]);

        $update = [
            'name'  => $data['name'],
            'email' => $data['email'],
            'rol'   => $data['rol'],
        ];

        if (!empty($data['password'])) {
            $update['password'] = Hash::make($data['password']);
        }

        $user->update($update);

        $this->logActividad('editado', 'Usuarios', "Usuario {$user->name} actualizado (rol: {$user->rol}).", $user->id);

        return response()->json($user->fresh()->only(['id', 'name', 'email', 'rol', 'created_at']));
    }

    public function destroy(Request $request, $id)
    {
        $this->soloAdmin($request);

        if ($request->user()->id == $id) {
            return response()->json(['message' => 'No puedes eliminar tu propio usuario.'], 422);
        }

        $user = User::findOrFail($id);
        $nombre = $user->name;
        $user->delete();

        $this->logActividad('eliminado', 'Usuarios', "Usuario {$nombre} eliminado.", $id);

        return response()->json(['message' => 'Usuario eliminado.']);
    }
}
