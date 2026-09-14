<?php

namespace App\Http\Controllers;

use App\Models\Cargo;
use App\Traits\SoloAdmin;
use Illuminate\Http\Request;

class CargoController extends Controller
{
    use SoloAdmin;

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
        $request->validate([
            'nombre' => 'required|string|max:50|unique:cargos',
        ]);

        $cargo = Cargo::create([
            'nombre'     => $request->nombre,
            'estado'     => 'Activo',
            'id_usuario' => $request->user()->id,
        ]);

        return response()->json($cargo, 201);
    }

    public function show($id)
    {
        return response()->json(Cargo::findOrFail($id));
    }

    public function update(Request $request, $id)
    {
        $cargo = Cargo::findOrFail($id);

        $request->validate([
            'nombre' => "required|string|max:50|unique:cargos,nombre,{$id}",
            'estado' => 'required|string|max:20',
        ]);

        $cargo->update($request->only(['nombre', 'estado']));

        return response()->json($cargo);
    }

    public function destroy($id)
    {
        $cargo = Cargo::findOrFail($id);
        $cargo->update(['estado' => 'Inactivo']);

        return response()->json(['message' => 'Cargo desactivado.']);
    }

    // Borrado permanente: solo administradores.
    public function eliminar(Request $request, $id)
    {
        $this->soloAdmin($request);

        $cargo = Cargo::findOrFail($id);

        abort_if(
            $cargo->empleados()->exists(),
            422,
            'No se puede eliminar: hay empleados asignados a este cargo.'
        );

        $cargo->delete();

        return response()->json(['message' => 'Cargo eliminado permanentemente.']);
    }
}
