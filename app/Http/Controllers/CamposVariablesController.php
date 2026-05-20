<?php

namespace App\Http\Controllers;

use App\Models\CampoVariable;
use App\Models\InformacionLaboral;
use App\Traits\LogsActividad;
use Illuminate\Http\Request;

class CamposVariablesController extends Controller
{
    use LogsActividad;

    public function index()
    {
        $campos = CampoVariable::whereIn('nombre_campo', ['ihss', 'salario_minimo'])->get()
            ->keyBy('nombre_campo');

        return response()->json([
            'ihss'          => (float) ($campos['ihss']->monto          ?? 297.58),
            'salario_minimo'=> (float) ($campos['salario_minimo']->monto ?? 16317.60),
        ]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'ihss'          => 'required|numeric|min:0',
            'salario_minimo'=> 'required|numeric|min:0',
        ]);

        $userId = $request->user()->id;

        // ── Actualizar IHSS ──────────────────────────────────────────────────
        CampoVariable::updateOrCreate(
            ['nombre_campo' => 'ihss'],
            ['monto' => $data['ihss'], 'id_usuario' => $userId]
        );

        // ── Actualizar salario mínimo ────────────────────────────────────────
        $anterior = (float) (CampoVariable::where('nombre_campo', 'salario_minimo')->value('monto') ?? 0);
        $nuevo    = (float) $data['salario_minimo'];

        CampoVariable::updateOrCreate(
            ['nombre_campo' => 'salario_minimo'],
            ['monto' => $nuevo, 'id_usuario' => $userId]
        );

        $empleadosActualizados = 0;

        if ($nuevo !== $anterior) {
            // Actualizar salarios de todos los empleados con usa_salario_minimo = true
            $infos = InformacionLaboral::where('usa_salario_minimo', true)
                ->where('estado', 'Activo')
                ->get();

            foreach ($infos as $il) {
                $il->update([
                    'salario_base'       => $nuevo,
                    'salario_quincenal'  => round($nuevo / 2,      2),
                    'salario_diario'     => round($nuevo / 30,     2),
                    'salario_por_hora'   => round($nuevo / 30 / 8, 2),
                ]);
            }

            $empleadosActualizados = $infos->count();

            $this->logActividad(
                'editado',
                'Campos Variables',
                "Salario mínimo actualizado de L.{$anterior} a L.{$nuevo}. {$empleadosActualizados} empleado(s) actualizado(s)."
            );
        }

        $this->logActividad(
            'editado',
            'Campos Variables',
            "IHSS actualizado a L.{$data['ihss']}."
        );

        return response()->json([
            'message'               => 'Campos actualizados correctamente.',
            'empleados_actualizados' => $empleadosActualizados,
        ]);
    }
}
