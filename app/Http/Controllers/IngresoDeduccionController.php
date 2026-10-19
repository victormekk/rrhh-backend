<?php

namespace App\Http\Controllers;

use App\Models\IngresoDeduccion;
use App\Traits\LogsActividad;
use App\Traits\SoloAdmin;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

// Ingresos y deducciones recurrentes por empleado (préstamos, adelantos, bonos
// fijos). Se cargan solos en cada planilla; ver App\Services\IngresosDeduccionesPlanilla.
class IngresoDeduccionController extends Controller
{
    use LogsActividad, SoloAdmin;

    public function index(Request $request)
    {
        $registros = IngresoDeduccion::with('empleado:id,nombres,apellidos')
            ->withCount(['aplicaciones', 'aplicaciones as cuotas_pagadas' => fn ($q) => $q->where('aplicada', true)])
            ->when($request->search, fn ($q, $s) => $q->where(fn ($w) => $w
                ->where('concepto', 'like', "%$s%")
                ->orWhereHas('empleado', fn ($e) => $e
                    ->whereRaw("CONCAT(nombres, ' ', apellidos) like ?", ["%$s%"]))
            ))
            ->when($request->tipo,   fn ($q, $t) => $q->where('tipo', $t))
            ->when($request->estado, fn ($q, $e) => $q->where('estado', $e))
            ->when($request->id_empleado, fn ($q, $id) => $q->where('id_empleado', $id))
            ->orderByRaw("FIELD(estado, 'Activo', 'Pausado', 'Completado', 'Cancelado')")
            ->orderByDesc('id')
            ->paginate(20);

        return response()->json($registros);
    }

    public function show($id)
    {
        $registro = IngresoDeduccion::with([
            'empleado:id,nombres,apellidos',
            'aplicaciones' => fn ($q) => $q->with('planilla:id,nombre_planilla,estado,fecha_generada')
                ->orderByDesc('id'),
        ])->findOrFail($id);

        return response()->json($registro);
    }

    public function store(Request $request)
    {
        $data = $this->validar($request);
        $data['id_usuario'] = $request->user()->id;
        $data['estado']     = 'Activo';

        $registro = IngresoDeduccion::create($data)->load('empleado:id,nombres,apellidos');

        $this->logActividad('creado', 'Ingresos/Deducciones', sprintf(
            "%s '%s' de L %s registrado para %s.",
            $registro->tipo === 'Ingreso' ? 'Ingreso' : 'Deducción', $registro->concepto,
            number_format((float) $registro->monto, 2), $this->nombre($registro)
        ), $registro->id);

        return response()->json($registro, 201);
    }

    public function update(Request $request, $id)
    {
        $registro = IngresoDeduccion::withCount('aplicaciones')->findOrFail($id);
        $data     = $this->validar($request, $registro);

        // Con cuotas ya cargadas en planillas no se cambia el empleado ni el tipo:
        // el historial dejaría de cuadrar. Para eso se cancela y se registra otro.
        if ($registro->aplicaciones_count > 0) {
            abort_if(
                $data['id_empleado'] != $registro->id_empleado || $data['tipo'] !== $registro->tipo,
                422,
                'Ya tiene cuotas en planillas: no se puede cambiar el empleado ni el tipo.'
            );
        }

        $registro->update($data);
        $registro->recalcularEstado();

        $this->logActividad('editado', 'Ingresos/Deducciones',
            "'{$registro->concepto}' de {$this->nombre($registro)} editado.", $registro->id);

        return response()->json($registro->fresh('empleado:id,nombres,apellidos'));
    }

    // Pausar, reanudar o cancelar (terminarlo antes de tiempo, ej. pagó el préstamo completo).
    public function cambiarEstado(Request $request, $id)
    {
        $registro = IngresoDeduccion::findOrFail($id);
        $data = $request->validate(['estado' => ['required', Rule::in(['Activo', 'Pausado', 'Cancelado'])]]);

        abort_if($registro->estado === 'Completado', 422, 'Ya terminó de pagarse.');

        $registro->update($data);
        $registro->recalcularEstado();

        $this->logActividad('editado', 'Ingresos/Deducciones', sprintf(
            "'%s' de %s pasó a %s.", $registro->concepto, $this->nombre($registro), $registro->estado
        ), $registro->id);

        return response()->json($registro->fresh('empleado:id,nombres,apellidos'));
    }

    public function destroy(Request $request, $id)
    {
        $this->soloAdmin($request);

        $registro = IngresoDeduccion::withCount('aplicaciones')->findOrFail($id);
        abort_if(
            $registro->aplicaciones_count > 0,
            422,
            'Ya tiene cuotas en planillas. Cancélelo en vez de eliminarlo para conservar el historial.'
        );

        $registro->delete();

        $this->logActividad('eliminado', 'Ingresos/Deducciones',
            "'{$registro->concepto}' de {$this->nombre($registro)} eliminado.", $id);

        return response()->json(['message' => 'Registro eliminado.']);
    }

    private function validar(Request $request, ?IngresoDeduccion $registro = null): array
    {
        $data = $request->validate([
            'id_empleado'  => 'required|exists:empleados,id',
            'tipo'         => ['required', Rule::in(IngresoDeduccion::TIPOS)],
            'concepto'     => 'required|string|max:50',
            'columna'      => ['required', Rule::in(IngresoDeduccion::COLUMNAS[$request->input('tipo')] ?? [])],
            'monto'        => 'required|numeric|min:0.01|max:999999',
            'frecuencia'   => ['required', Rule::in(IngresoDeduccion::FRECUENCIAS)],
            'quincena_mes' => 'nullable|required_if:frecuencia,Mensual|in:1,2',
            'total_cuotas' => 'nullable|integer|min:1|max:600',
            'fecha_inicio' => 'required|date',
            'observacion'  => 'nullable|string|max:200',
        ], [
            'columna.in'                => 'La columna no corresponde al tipo elegido.',
            'quincena_mes.required_if'  => 'Elija en qué quincena del mes se aplica.',
        ]);

        // Sin comas ni dos puntos: son los separadores del texto "Concepto: monto, ..." de la planilla.
        $data['concepto']     = trim(preg_replace('/[,:]/', '', $data['concepto']));
        $data['quincena_mes'] = $data['frecuencia'] === 'Mensual' ? (int) $data['quincena_mes'] : null;

        if ($registro && $data['total_cuotas'] !== null) {
            $pagadas = $registro->aplicaciones()->where('aplicada', true)->count();
            abort_if($data['total_cuotas'] < $pagadas, 422,
                "Ya se pagaron {$pagadas} cuota(s): el total no puede ser menor.");
        }

        return $data;
    }

    private function nombre(IngresoDeduccion $r): string
    {
        $e = $r->empleado()->first(['nombres', 'apellidos']);

        return trim("{$e?->nombres} {$e?->apellidos}");
    }
}
