<?php

namespace App\Http\Controllers;

use App\Models\Puesto;
use Illuminate\Http\Request;

class PuestoController extends Controller
{
    public function index(Request $request)
    {
        $query = Puesto::when(
            $request->boolean('solo_activos', true),
            fn($q) => $q->where('estado', 'Activo')
        )->orderBy('nombre');

        return response()->json($query->get());
    }

    public function store(Request $request)
    {
        $request->validate([
            'nombre' => 'required|string|max:50|unique:puestos',
        ]);

        $puesto = Puesto::create([
            'nombre'     => $request->nombre,
            'estado'     => 'Activo',
            'id_usuario' => $request->user()->id,
        ]);

        return response()->json($puesto, 201);
    }

    public function show($id)
    {
        return response()->json(Puesto::findOrFail($id));
    }

    public function update(Request $request, $id)
    {
        $puesto = Puesto::findOrFail($id);

        $request->validate([
            'nombre' => "required|string|max:50|unique:puestos,nombre,{$id}",
            'estado' => 'required|string|max:20',
        ]);

        $puesto->update($request->only(['nombre', 'estado']));

        return response()->json($puesto);
    }

    public function destroy($id)
    {
        $puesto = Puesto::findOrFail($id);
        $puesto->update(['estado' => 'Inactivo']);

        return response()->json(['message' => 'Puesto desactivado.']);
    }
}
