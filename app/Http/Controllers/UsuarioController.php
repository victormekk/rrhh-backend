<?php

namespace App\Http\Controllers;

use App\Models\Rol;
use App\Models\User;
use App\Traits\LogsActividad;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class UsuarioController extends Controller
{
    use LogsActividad;

    public function index(Request $request)
    {
        return User::with('rol:id,nombre,es_admin')
            ->select('id', 'name', 'email', 'id_rol', 'activo', 'created_at')
            ->when($request->search, fn($q, $s) =>
                $q->where(fn($q) => $q->where('name', 'like', "%$s%")
                  ->orWhere('email', 'like', "%$s%"))
            )
            ->orderBy('name')
            ->paginate(20);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name'     => 'required|string|max:100',
            'email'    => 'required|email|max:100|unique:users,email',
            'password' => 'required|string|min:8',
            'id_rol'   => 'required|exists:roles,id',
        ], ['id_rol.required' => 'Seleccione un rol.']);

        $rol = Rol::find($data['id_rol']);
        $this->exigirAdminParaRolAdmin($request, $rol);

        $user = User::create([
            'name'     => $data['name'],
            'email'    => $data['email'],
            'password' => Hash::make($data['password']),
            'id_rol'   => $rol->id,
        ]);

        $this->logActividad('creado', 'Usuarios', "Usuario {$user->name} ({$user->email}) creado con rol {$rol->nombre}.", $user->id);

        return response()->json($this->datos($user), 201);
    }

    public function update(Request $request, $id)
    {
        $user = User::with('rol')->findOrFail($id);

        $data = $request->validate([
            'name'     => 'required|string|max:100',
            'email'    => "required|email|max:100|unique:users,email,{$id}",
            'id_rol'   => 'required|exists:roles,id',
            'password' => 'nullable|string|min:8',
        ], ['id_rol.required' => 'Seleccione un rol.']);

        $rol = Rol::find($data['id_rol']);
        $cambiaRol = (int) $user->id_rol !== (int) $rol->id;

        if ($cambiaRol && $request->user()->id == $user->id) {
            return response()->json(['message' => 'No puedes cambiar tu propio rol.'], 422);
        }

        // Editar a un Administrador o dar el rol Administrador: solo otro Administrador.
        $this->exigirAdminParaRolAdmin($request, $user->rol);
        $this->exigirAdminParaRolAdmin($request, $rol);

        $update = [
            'name'   => $data['name'],
            'email'  => $data['email'],
            'id_rol' => $rol->id,
        ];

        if (!empty($data['password'])) {
            $update['password'] = Hash::make($data['password']);
        }

        $user->update($update);

        $this->logActividad('editado', 'Usuarios', "Usuario {$user->name} actualizado (rol: {$rol->nombre}).", $user->id);

        return response()->json($this->datos($user));
    }

    public function cambiarEstado(Request $request, $id)
    {
        if ($request->user()->id == $id) {
            return response()->json(['message' => 'No puedes deshabilitar tu propio usuario.'], 422);
        }

        $data = $request->validate(['activo' => 'required|boolean']);

        $user = User::with('rol')->findOrFail($id);
        $this->exigirAdminParaRolAdmin($request, $user->rol);
        $user->update(['activo' => $data['activo']]);

        // Los tokens no se revocan aquí: en su siguiente acción VerificaUsuarioActivo
        // los revoca y responde con el aviso de cuenta deshabilitada.
        $estado = $user->activo ? 'habilitado' : 'deshabilitado';
        $this->logActividad('editado', 'Usuarios', "Usuario {$user->name} {$estado}.", $user->id);

        return response()->json($this->datos($user));
    }

    public function destroy(Request $request, $id)
    {
        if ($request->user()->id == $id) {
            return response()->json(['message' => 'No puedes eliminar tu propio usuario.'], 422);
        }

        $user = User::with('rol')->findOrFail($id);
        $this->exigirAdminParaRolAdmin($request, $user->rol);

        if ($user->activo) {
            return response()->json(['message' => 'Primero deshabilite al usuario para poder eliminarlo.'], 422);
        }

        $nombre = $user->name;
        // Borrado lógico (SoftDeletes): el usuario se conserva en la base de datos
        // y su nombre sigue apareciendo en la bitácora.
        $user->delete();

        $this->logActividad('eliminado', 'Usuarios', "Usuario {$nombre} eliminado.", $id);

        return response()->json(['message' => 'Usuario eliminado.']);
    }

    // Roles para el selector del formulario de usuario.
    public function roles()
    {
        return response()->json(
            Rol::orderByDesc('es_admin')->orderBy('nombre')->get(['id', 'nombre', 'descripcion', 'es_admin'])
        );
    }

    private function exigirAdminParaRolAdmin(Request $request, ?Rol $rol): void
    {
        if ($rol?->es_admin && !$request->user()->esAdmin()) {
            abort(response()->json(['message' => 'Solo un Administrador puede gestionar usuarios con el rol Administrador.'], 403));
        }
    }

    private function datos(User $user): array
    {
        $user->load('rol:id,nombre,es_admin');

        return [
            ...$user->only(['id', 'name', 'email', 'id_rol', 'activo', 'created_at']),
            'rol' => $user->rol,
        ];
    }
}
