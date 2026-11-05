<?php

namespace App\Http\Controllers;

use App\Models\Rol;
use App\Traits\LogsActividad;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

// Configuración → Gestión de Roles: roles con permisos por checks.
class RolController extends Controller
{
    use LogsActividad;

    public function index()
    {
        $roles = Rol::withCount('usuarios')
            ->orderByDesc('es_admin')
            ->orderBy('nombre')
            ->get()
            ->map(fn (Rol $rol) => [
                'id'             => $rol->id,
                'nombre'         => $rol->nombre,
                'descripcion'    => $rol->descripcion,
                'es_admin'       => $rol->es_admin,
                'permisos'       => $rol->permisosEfectivos(),
                'usuarios_count' => $rol->usuarios_count,
            ]);

        return response()->json($roles);
    }

    // Módulos y permisos que se muestran como checks (config/permisos.php).
    // Los grupos que en el menú son submenús (Planillas, Reportes, Configuración)
    // van en orden alfabético, igual que el menú; General sigue el orden del menú principal.
    public function catalogo()
    {
        $catalogo = array_map(function (array $grupo) {
            if ($grupo['grupo'] !== 'General') {
                uasort($grupo['modulos'], fn ($a, $b) => strcasecmp(
                    \Illuminate\Support\Str::ascii($a['nombre']),
                    \Illuminate\Support\Str::ascii($b['nombre'])
                ));
            }

            return $grupo;
        }, config('permisos'));

        return response()->json($catalogo);
    }

    public function store(Request $request)
    {
        $data = $this->validar($request);

        $rol = Rol::create([
            'nombre'      => $data['nombre'],
            'descripcion' => $data['descripcion'] ?? null,
            'es_admin'    => false,
            'permisos'    => $this->permisosPermitidos($request, $data['permisos'] ?? [], []),
        ]);

        $this->logActividad('creado', 'Roles', "Rol {$rol->nombre} creado con " . count($rol->permisos) . ' permiso(s).', $rol->id);

        return response()->json($rol, 201);
    }

    public function update(Request $request, $id)
    {
        $rol = Rol::findOrFail($id);
        abort_if($rol->es_admin, 422, 'El rol Administrador tiene acceso total y no se puede modificar.');

        $data = $this->validar($request, $rol->id);

        $rol->update([
            'nombre'      => $data['nombre'],
            'descripcion' => $data['descripcion'] ?? null,
            'permisos'    => $this->permisosPermitidos($request, $data['permisos'] ?? [], $rol->permisos ?? []),
        ]);

        $this->logActividad('editado', 'Roles', "Rol {$rol->nombre} actualizado (" . count($rol->permisos) . ' permiso(s)).', $rol->id);

        return response()->json($rol);
    }

    public function destroy($id)
    {
        $rol = Rol::findOrFail($id);
        abort_if($rol->es_admin, 422, 'El rol Administrador no se puede eliminar.');

        $enUso = $rol->usuarios()->count();
        abort_if($enUso > 0, 422, "No se puede eliminar: {$enUso} usuario(s) tienen este rol. Asígnales otro rol primero.");

        DB::transaction(function () use ($rol) {
            // Usuarios eliminados (borrado lógico) que tenían este rol: quedan sin rol.
            $rol->usuarios()->withTrashed()->update(['id_rol' => null]);
            $rol->delete();
        });

        $this->logActividad('eliminado', 'Roles', "Rol {$rol->nombre} eliminado.", $rol->id);

        return response()->json(['message' => 'Rol eliminado.']);
    }

    private function validar(Request $request, ?int $id = null): array
    {
        return $request->validate([
            'nombre'      => ['required', 'string', 'max:50', Rule::unique('roles', 'nombre')->ignore($id)],
            'descripcion' => 'nullable|string|max:255',
            'permisos'    => 'array',
            'permisos.*'  => ['string', Rule::in(Rol::todosLosPermisos())],
        ], [
            'nombre.unique' => 'Ya existe un rol con ese nombre.',
        ]);
    }

    // Nadie puede dar permisos que no tiene: quien no es Administrador solo cambia
    // los permisos que él mismo tiene; los demás del rol quedan como estaban.
    private function permisosPermitidos(Request $request, array $pedidos, array $actuales): array
    {
        $usuario = $request->user();
        if ($usuario->esAdmin()) {
            return array_values(array_unique($pedidos));
        }

        $mios = $usuario->permisos();

        return array_values(array_unique([
            ...array_intersect($pedidos, $mios),
            ...array_diff($actuales, $mios),
        ]));
    }
}
