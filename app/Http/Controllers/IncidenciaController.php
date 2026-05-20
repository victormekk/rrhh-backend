<?php

namespace App\Http\Controllers;

use App\Models\Incidencia;
use App\Traits\LogsActividad;
use Illuminate\Http\Request;

class IncidenciaController extends Controller
{
    use LogsActividad;
    public function index(Request $request)
    {
        $incidencias = Incidencia::with('empleado:id,nombres,apellidos,id_departamento')
            ->when($request->search, fn($q, $s) =>
                $q->whereHas('empleado', fn($eq) =>
                    $eq->where('nombres', 'like', "%$s%")
                       ->orWhere('apellidos', 'like', "%$s%")
                )
            )
            ->when($request->grado, fn($q, $g) => $q->where('grado', $g))
            ->when($request->fecha_inicio, fn($q, $f) => $q->where('fecha_incidencia', '>=', $f))
            ->when($request->fecha_fin,    fn($q, $f) => $q->where('fecha_incidencia', '<=', $f))
            ->when($request->id_empleado,  fn($q, $id) => $q->where('id_empleado', $id))
            ->orderByDesc('fecha_incidencia')
            ->orderByDesc('id')
            ->paginate(20);

        return response()->json($incidencias);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'id_empleado'      => 'required|exists:empleados,id',
            'fecha_incidencia' => 'required|date',
            'titulo'           => 'required|string|max:50',
            'descripcion'      => 'required|string|max:300',
            'grado'            => 'required|in:Leve,Moderada,Grave',
        ]);

        $data['id_usuario'] = $request->user()->id;

        $incidencia = Incidencia::create($data);

        $incidencia->load('empleado:id,nombres,apellidos');
        $this->logActividad('creado', 'Incidencias', "Incidencia '{$incidencia->titulo}' registrada para {$incidencia->empleado->nombres} {$incidencia->empleado->apellidos}.", $incidencia->id);

        return response()->json($incidencia, 201);
    }

    public function show($id)
    {
        return response()->json(
            Incidencia::with('empleado:id,nombres,apellidos')->findOrFail($id)
        );
    }

    public function update(Request $request, $id)
    {
        $incidencia = Incidencia::findOrFail($id);

        $data = $request->validate([
            'id_empleado'      => 'sometimes|exists:empleados,id',
            'fecha_incidencia' => 'sometimes|date',
            'titulo'           => 'sometimes|string|max:50',
            'descripcion'      => 'sometimes|string|max:300',
            'grado'            => 'sometimes|in:Leve,Moderada,Grave',
        ]);

        $incidencia->update($data);

        return response()->json(
            $incidencia->fresh(['empleado:id,nombres,apellidos'])
        );
    }

    public function destroy($id)
    {
        $inc = Incidencia::with('empleado:id,nombres,apellidos')->findOrFail($id);
        $inc->delete();
        $this->logActividad('eliminado', 'Incidencias', "Incidencia '{$inc->titulo}' de {$inc->empleado->nombres} {$inc->empleado->apellidos} eliminada.", $id);

        return response()->json(['message' => 'Incidencia eliminada.']);
    }
}
