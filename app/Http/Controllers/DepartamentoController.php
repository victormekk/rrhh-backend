<?php

namespace App\Http\Controllers;

use App\Models\Departamento;
use Illuminate\Http\Request;

class DepartamentoController extends Controller
{
    public function index(Request $request)
    {
        $query = Departamento::when(
            $request->boolean('solo_activos', true),
            fn($q) => $q->where('estado', 'Activo')
        )->orderBy('nombre');

        return response()->json($query->get());
    }

    public function store(Request $request)
    {
        $request->validate([
            'nombre' => 'required|string|max:50|unique:departamentos',
        ]);

        $departamento = Departamento::create([
            'nombre'     => $request->nombre,
            'estado'     => 'Activo',
            'id_usuario' => $request->user()->id,
        ]);

        return response()->json($departamento, 201);
    }

    public function show($id)
    {
        return response()->json(Departamento::findOrFail($id));
    }

    public function update(Request $request, $id)
    {
        $departamento = Departamento::findOrFail($id);

        $request->validate([
            'nombre' => "required|string|max:50|unique:departamentos,nombre,{$id}",
            'estado' => 'required|string|max:15',
        ]);

        $departamento->update($request->only(['nombre', 'estado']));

        return response()->json($departamento);
    }

    public function destroy($id)
    {
        $departamento = Departamento::findOrFail($id);
        $departamento->update(['estado' => 'Inactivo']);

        return response()->json(['message' => 'Departamento desactivado.']);
    }
}
