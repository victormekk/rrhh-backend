<?php

namespace App\Http\Controllers;

use App\Models\Departamento;
use App\Traits\SoloAdmin;
use Illuminate\Http\Request;

class DepartamentoController extends Controller
{
    use SoloAdmin;

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
        $request->merge(['nombre' => strtoupper(trim((string) $request->nombre))]);

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

        $request->merge(['nombre' => strtoupper(trim((string) $request->nombre))]);

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

        $nombres = $departamento->empleados()
            ->whereHas('informacionLaboral', fn($q) => $q->where('estado', 'Activo'))
            ->get(['nombres', 'apellidos'])
            ->map(fn($e) => trim("{$e->nombres} {$e->apellidos}"));

        abort_if(
            $nombres->isNotEmpty(),
            422,
            'No se puede desactivar: hay empleados activos en este departamento (' . $nombres->implode(', ') . '). Muévelos a otro departamento primero.'
        );

        $departamento->update(['estado' => 'Inactivo']);

        return response()->json(['message' => 'Departamento desactivado.']);
    }

    // Borrado permanente: solo administradores.
    public function eliminar(Request $request, $id)
    {
        $this->soloAdmin($request);

        $departamento = Departamento::findOrFail($id);

        abort_if(
            $departamento->estado !== 'Inactivo',
            422,
            'Solo se pueden eliminar departamentos inactivos. Desactívalo primero.'
        );

        $nombres = $departamento->empleados()->get(['nombres', 'apellidos'])
            ->map(fn($e) => trim("{$e->nombres} {$e->apellidos}"));

        abort_if(
            $nombres->isNotEmpty(),
            422,
            'No se puede eliminar: hay empleados asignados a este departamento (' . $nombres->implode(', ') . ').'
        );

        $departamento->delete();

        return response()->json(['message' => 'Departamento eliminado permanentemente.']);
    }
}
