<?php

namespace App\Http\Controllers;

use App\Models\Incidencia;
use App\Traits\GeneraCorrelativo;
use App\Traits\LogsActividad;
use App\Traits\NombraArchivos;
use App\Traits\SoloAdmin;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class IncidenciaController extends Controller
{
    use LogsActividad, NombraArchivos, GeneraCorrelativo, SoloAdmin;
    public function index(Request $request)
    {
        $anuladas = $request->boolean('anuladas');

        $incidencias = Incidencia::with('empleado:id,nombres,apellidos,id_departamento')
            ->when($anuladas, fn($q) => $q->onlyTrashed()->with('usuarioAnula:id,name'))
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
            ->when($anuladas, fn($q) => $q->orderByDesc('anulada_at'))
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

    // No borra la fila: la anula (borrado lógico) guardando quién, cuándo y por qué.
    public function destroy(Request $request, $id)
    {
        $this->soloAdmin($request);

        $data = $request->validate([
            'motivo' => 'required|string|max:300',
        ]);

        $inc = Incidencia::with('empleado:id,nombres,apellidos')->findOrFail($id);

        DB::transaction(function () use ($inc, $data, $request) {
            $inc->forceFill([
                'anulada_por'      => $request->user()->id,
                'motivo_anulacion' => $data['motivo'],
            ])->save();
            $inc->delete();
        });

        $this->logActividad('anulado', 'Incidencias', "Incidencia '{$inc->titulo}' de {$inc->empleado->nombres} {$inc->empleado->apellidos} anulada. Motivo: {$data['motivo']}", $inc->id);

        return response()->json(['message' => 'Incidencia anulada.']);
    }

    public function restaurar(Request $request, $id)
    {
        $this->soloAdmin($request);

        $inc = Incidencia::onlyTrashed()->with('empleado:id,nombres,apellidos')->findOrFail($id);

        DB::transaction(function () use ($inc) {
            $inc->restore();
            $inc->forceFill(['anulada_por' => null, 'motivo_anulacion' => null])->save();
        });

        $this->logActividad('editado', 'Incidencias', "Incidencia '{$inc->titulo}' de {$inc->empleado->nombres} {$inc->empleado->apellidos} restaurada.", $inc->id);

        return response()->json(['message' => 'Incidencia restaurada.']);
    }

    public function pdf($id)
    {
        $incidencia = Incidencia::with([
            'empleado.informacionLaboral',
            'empleado.cargo',
            'empleado.departamento',
        ])->findOrFail($id);

        $correlativo = $this->siguienteCorrelativo('incidencia', $incidencia->id);

        $pdf = Pdf::loadView('incidencias.constancia', compact('incidencia', 'correlativo'))
            ->setPaper('letter', 'portrait');

        $nombres   = $incidencia->empleado->nombres;
        $apellidos = $incidencia->empleado->apellidos;

        return $pdf->download($this->nombreArchivo('Incidencia', "{$nombres} {$apellidos}", 'pdf'));
    }
}
