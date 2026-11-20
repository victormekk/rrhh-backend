<?php

namespace App\Services;

use App\Models\CabeceraPlanilla;
use App\Models\CampoVariable;
use App\Models\DetallePlanilla;
use App\Models\Empleado;
use App\Models\IngresoDeduccionAplicada;
use App\Models\OtroIngreso;

// Mantiene al día quién está en las planillas de pago abiertas cuando cambia un
// empleado, para no tener que anular y volver a crear la planilla:
//  - nuevo, reintegrado o pasado a Activo  → se agrega a las abiertas de su contrato;
//  - dado de baja o suspendido             → se quita de las abiertas;
//  - cambio de contrato (Fijo ↔ Extra)     → se pasa de una a otra.
// Las cerradas no se tocan nunca.
class PlanillasAbiertas
{
    public const TIPO_POR_CONTRATO = ['Fijo' => 'Fijos', 'Extra' => 'Extras'];

    public function __construct(private IngresosDeduccionesPlanilla $ingresosDeducciones)
    {
    }

    // Devuelve ['agregado' => [nombres], 'quitado' => [nombres]].
    public function sincronizarEmpleado(int $idEmpleado, ?int $idUsuario = null): array
    {
        $empleado = Empleado::with(['informacionLaboral.banco', 'departamento'])->find($idEmpleado);
        $il       = $empleado?->informacionLaboral;
        $tipo     = $il && $il->estado === 'Activo' ? (self::TIPO_POR_CONTRATO[$il->tipo_contrato] ?? null) : null;

        $resultado = ['agregado' => [], 'quitado' => []];

        $abiertas = CabeceraPlanilla::where('estado', 'Activo')
            ->whereIn('tipo_planilla', array_values(self::TIPO_POR_CONTRATO))
            ->orderBy('fecha_generada')
            ->lockForUpdate()
            ->get();

        foreach ($abiertas as $planilla) {
            $detalle = DetallePlanilla::where('id_cabecera_planilla', $planilla->id)
                ->where('id_empleado', $idEmpleado)->first();

            if ($detalle && $planilla->tipo_planilla !== $tipo) {
                // Sus cuotas de ingresos/deducciones en esta planilla quedan libres para la próxima.
                IngresoDeduccionAplicada::where('id_detalle_planilla', $detalle->id)->delete();
                $detalle->delete();
                $resultado['quitado'][] = $planilla->nombre_planilla;
                continue;
            }

            // Solo entra a las planillas de fecha igual o posterior a su inicio (un
            // empleado que empieza el 20 no va en la planilla del 15).
            $yaInicio = !$il?->fecha_inicio || $il->fecha_inicio->lte($planilla->fecha_generada);
            if (!$detalle && $planilla->tipo_planilla === $tipo && $yaInicio) {
                $this->crearFila($planilla, $empleado, 0, $this->ihssFijo(), $idUsuario ?? $planilla->id_usuario);
                $this->ingresosDeducciones->cargar($planilla, soloEmpleado: $idEmpleado);
                $resultado['agregado'][] = $planilla->nombre_planilla;
            }
        }

        return $resultado;
    }

    public function ihssFijo(): float
    {
        return (float) (CampoVariable::where('nombre_campo', 'ihss')->value('monto') ?? 297.58);
    }

    // Fila nueva de un empleado en la planilla, con los mismos valores iniciales que
    // al crearla: IHSS del campo variable (no en Extras), "Otros ingresos" registrados
    // para ese nombre de planilla y el resto de deducciones en 0.
    public function crearFila(CabeceraPlanilla $planilla, Empleado $emp, int $diasTrabajados, float $ihssFijo, ?int $idUsuario, $ingresos = null): DetallePlanilla
    {
        $il          = $emp->informacionLaboral;
        $salarioBase = round($il->salario_diario * $diasTrabajados, 2);

        $ingresos      ??= OtroIngreso::where('nombre_planilla', $planilla->nombre_planilla)->where('id_empleado', $emp->id)->get();
        $otrosIngresos = (float) $ingresos->sum('monto');
        $descIngresos  = $ingresos->pluck('descripcion')->filter()->implode(', ');

        // El personal de contrato Extra no cotiza IHSS en esta planilla.
        $ihss = $planilla->tipo_planilla === 'Extras' ? 0 : $ihssFijo;

        // RAP e ISR se editan a mano por empleado; los ingresos/deducciones recurrentes
        // se suman después (ver IngresosDeduccionesPlanilla::cargar).
        return DetallePlanilla::create([
            'id_cabecera_planilla'   => $planilla->id,
            'id_empleado'            => $emp->id,
            'nombre_planilla'        => $planilla->nombre_planilla,
            'departamento'           => $emp->departamento?->nombre ?? '',
            'tipo_planilla'          => $planilla->tipo_planilla,
            'dias_trabajados'        => $diasTrabajados,
            'salario_diario'         => $il->salario_diario,
            'salario_base'           => $salarioBase,
            'desc_ingresos'          => $descIngresos ?: null,
            'otros_ingresos'         => $otrosIngresos,
            'horas_extras'           => 0,
            'monto_horas_extras'     => 0,
            'ihss'                   => $ihss,
            'retencion_ahorro'       => 0,
            'isr'                    => 0,
            'crefisa'                => 0,
            'transporte'             => 0,
            'radios'                 => 0,
            'uniforme'               => 0,
            'garden'                 => 0,
            'i_vecinal'              => 0,
            'otras_deducciones'      => 0,
            'desc_otras_deducciones' => null,
            'deduccion_neta'         => $ihss,
            'salario_neto'           => $salarioBase + $otrosIngresos - $ihss,
            'cuenta_banco'           => $il->cuentaParaPago(),
            'fecha_generada'         => $planilla->fecha_generada,
            'id_usuario'             => $idUsuario,
        ]);
    }

    // Texto para el aviso: "Agregado a: X. Quitado de: Y."
    public static function resumen(array $r): ?string
    {
        $partes = [];
        if ($r['agregado']) {
            $partes[] = 'Agregado a la planilla abierta: ' . implode(', ', $r['agregado']) . '.';
        }
        if ($r['quitado']) {
            $partes[] = 'Quitado de la planilla abierta: ' . implode(', ', $r['quitado']) . '.';
        }

        return $partes ? implode(' ', $partes) : null;
    }
}
