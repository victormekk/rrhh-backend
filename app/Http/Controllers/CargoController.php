<?php

namespace App\Http\Controllers;

use App\Models\Cargo;
use App\Traits\LogsActividad;
use App\Traits\SoloAdmin;
use Illuminate\Http\Request;

class CargoController extends Controller
{
    use LogsActividad, SoloAdmin;

    public function index(Request $request)
    {
        $query = Cargo::when(
            $request->boolean('solo_activos', true),
            fn($q) => $q->where('estado', 'Activo')
        )->orderBy('nombre');

        return response()->json($query->get());
    }

    public function store(Request $request)
    {
        $request->merge(['nombre' => strtoupper(trim((string) $request->nombre))]);

        $request->validate([
            'nombre' => 'required|string|max:50|unique:cargos',
        ]);

        $cargo = Cargo::create([
            'nombre'     => $request->nombre,
            'estado'     => 'Activo',
            'id_usuario' => $request->user()->id,
        ]);

        $this->logActividad('creado', 'Cargos', "Cargo '{$cargo->nombre}' creado.", $cargo->id);

        return response()->json($cargo, 201);
    }

    public function show($id)
    {
        return response()->json(Cargo::findOrFail($id));
    }

    public function update(Request $request, $id)
    {
        $cargo = Cargo::findOrFail($id);

        $request->merge(['nombre' => strtoupper(trim((string) $request->nombre))]);

        $request->validate([
            'nombre' => "required|string|max:50|unique:cargos,nombre,{$id}",
            'estado' => 'required|string|max:20',
        ]);

        $antes = $cargo->only(['nombre', 'estado']);
        $cargo->update($request->only(['nombre', 'estado']));

        $cambios = [];
        if ($antes['nombre'] !== $cargo->nombre) $cambios[] = "nombre '{$antes['nombre']}' → '{$cargo->nombre}'";
        if ($antes['estado'] !== $cargo->estado) $cambios[] = "estado {$antes['estado']} → {$cargo->estado}";
        if ($cambios) {
            $this->logActividad('editado', 'Cargos', "Cargo '{$cargo->nombre}' editado: " . implode(', ', $cambios) . '.', $cargo->id);
        }

        return response()->json($cargo);
    }

    public function destroy($id)
    {
        $cargo = Cargo::findOrFail($id);

        $nombres = $cargo->empleados()
            ->whereHas('informacionLaboral', fn($q) => $q->where('estado', 'Activo'))
            ->get(['nombres', 'apellidos'])
            ->map(fn($e) => trim("{$e->nombres} {$e->apellidos}"));

        abort_if(
            $nombres->isNotEmpty(),
            422,
            'No se puede desactivar: hay empleados activos con este cargo (' . $nombres->implode(', ') . '). Muévelos a otro cargo primero.'
        );

        $cargo->update(['estado' => 'Inactivo']);
        $this->logActividad('editado', 'Cargos', "Cargo '{$cargo->nombre}' desactivado.", $cargo->id);

        return response()->json(['message' => 'Cargo desactivado.']);
    }

    // Borrado permanente: solo administradores.
    public function eliminar(Request $request, $id)
    {
        $this->soloAdmin($request);

        $cargo = Cargo::findOrFail($id);

        abort_if(
            $cargo->estado !== 'Inactivo',
            422,
            'Solo se pueden eliminar cargos inactivos. Desactívalo primero.'
        );

        $nombres = $cargo->empleados()->get(['nombres', 'apellidos'])
            ->map(fn($e) => trim("{$e->nombres} {$e->apellidos}"));

        abort_if(
            $nombres->isNotEmpty(),
            422,
            'No se puede eliminar: hay empleados asignados a este cargo (' . $nombres->implode(', ') . ').'
        );

        $cargo->delete();
        $this->logActividad('eliminado', 'Cargos', "Cargo '{$cargo->nombre}' eliminado permanentemente.", $cargo->id);

        return response()->json(['message' => 'Cargo eliminado permanentemente.']);
    }
}
