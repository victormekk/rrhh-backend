<?php

namespace App\Http\Controllers;

use App\Models\Banco;
use Illuminate\Http\Request;

class BancoController extends Controller
{
    public function index(Request $request)
    {
        $query = Banco::when(
            $request->boolean('solo_activos', true),
            fn($q) => $q->where('estado', 'Activo')
        )->orderBy('nombre');

        return response()->json($query->get());
    }

    public function store(Request $request)
    {
        $request->validate([
            'nombre' => 'required|string|max:30|unique:bancos',
        ]);

        $banco = Banco::create([
            'nombre'     => $request->nombre,
            'estado'     => 'Activo',
            'id_usuario' => $request->user()->id,
        ]);

        return response()->json($banco, 201);
    }

    public function show($id)
    {
        return response()->json(Banco::findOrFail($id));
    }

    public function update(Request $request, $id)
    {
        $banco = Banco::findOrFail($id);

        $request->validate([
            'nombre' => "required|string|max:30|unique:bancos,nombre,{$id}",
            'estado' => 'required|string|max:20',
        ]);

        $banco->update($request->only(['nombre', 'estado']));

        return response()->json($banco);
    }

    public function destroy($id)
    {
        $banco = Banco::findOrFail($id);
        $banco->update(['estado' => 'Inactivo']);

        return response()->json(['message' => 'Banco desactivado.']);
    }
}
