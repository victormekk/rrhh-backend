<?php

namespace App\Http\Controllers;

use App\Models\Banco;
use App\Models\CabeceraPlanilla;
use App\Models\CampoVariable;
use App\Models\DetallePlanilla;
use App\Models\Empleado;
use App\Traits\ConvierteMontoALetras;
use App\Traits\GeneraCorrelativo;
use App\Traits\LogsActividad;
use App\Traits\NombraArchivos;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;

class ConstanciaController extends Controller
{
    use LogsActividad, NombraArchivos, GeneraCorrelativo, ConvierteMontoALetras;

    private const MESES = [
        'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio',
        'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre',
    ];

    // IHSS fuera de una planilla: el resto de deducciones (RAP, ISR, Crefisa, etc.)
    // se llenan a mano por quincena y no existen fuera de una planilla generada,
    // así que solo se informa el IHSS. El monto configurado es quincenal, se
    // duplica para expresarlo como deducción mensual. Contrato "Extra" no cotiza.
    private function ihssMensual($il): float
    {
        if ($il->tipo_contrato === 'Extra') {
            return 0.0;
        }

        $ihssQuincenal = (float) (CampoVariable::where('nombre_campo', 'ihss')->value('monto') ?? 297.58);

        return round($ihssQuincenal * 2, 2);
    }

    public function laboral($id)
    {
        $emp = Empleado::with(['informacionLaboral', 'cargo', 'departamento'])->findOrFail($id);

        $il = $emp->informacionLaboral;
        abort_if(!$il || !$il->fecha_inicio, 422, 'El empleado no tiene información laboral registrada.');

        $fechaInicio    = Carbon::parse($il->fecha_inicio);
        $salarioMensual = (float) $il->salario_base;
        $ihssMensual    = $this->ihssMensual($il);
        $nombreMoneda   = $il->moneda === 'Dólares' ? 'DÓLARES' : 'LEMPIRAS';
        $montoEnLetras  = $this->montoEnLetras($salarioMensual, $nombreMoneda);
        $simboloMoneda  = $il->moneda === 'Dólares' ? 'US$' : 'L.';
        $meses          = self::MESES;
        $correlativo    = $this->siguienteCorrelativo('constancia_laboral', $emp->id);

        $pdf = Pdf::loadView('constancias.laboral', compact(
            'emp', 'fechaInicio', 'salarioMensual', 'ihssMensual', 'montoEnLetras', 'simboloMoneda', 'meses', 'correlativo'
        ))->setPaper('letter', 'portrait');

        $archivo = $this->nombreArchivo('ConstanciaLaboral', "{$emp->nombres} {$emp->apellidos}", 'pdf');

        $this->logActividad(
            'generado',
            'Constancias',
            "Constancia laboral emitida para {$emp->nombres} {$emp->apellidos}.",
            $emp->id
        );

        return $pdf->download($archivo)
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->header('Pragma', 'no-cache');
    }

    public function bancaria($id, $bancoId)
    {
        $emp   = Empleado::with(['informacionLaboral', 'cargo', 'departamento'])->findOrFail($id);
        $banco = Banco::findOrFail($bancoId);

        $il = $emp->informacionLaboral;
        abort_if(!$il || !$il->fecha_inicio, 422, 'El empleado no tiene información laboral registrada.');

        $fechaInicio    = Carbon::parse($il->fecha_inicio);
        $salarioMensual = (float) $il->salario_base;
        $ihssMensual    = $this->ihssMensual($il);
        $nombreMoneda   = $il->moneda === 'Dólares' ? 'DÓLARES' : 'LEMPIRAS';
        $montoEnLetras  = $this->montoEnLetras($salarioMensual, $nombreMoneda);
        $simboloMoneda  = $il->moneda === 'Dólares' ? 'US$' : 'L.';
        $meses          = self::MESES;
        $correlativo    = $this->siguienteCorrelativo('constancia_bancaria', $emp->id);

        $pdf = Pdf::loadView('constancias.bancaria', compact(
            'emp', 'banco', 'fechaInicio', 'salarioMensual', 'ihssMensual', 'montoEnLetras', 'simboloMoneda', 'meses', 'correlativo'
        ))->setPaper('letter', 'portrait');

        $archivo = $this->nombreArchivo('ConstanciaBancaria', "{$emp->nombres} {$emp->apellidos}", 'pdf');

        $this->logActividad(
            'generado',
            'Constancias',
            "Constancia bancaria emitida para {$emp->nombres} {$emp->apellidos} ({$banco->nombre}).",
            $emp->id
        );

        return $pdf->download($archivo)
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->header('Pragma', 'no-cache');
    }

    // Planillas CERRADAS en las que aparece este empleado, para elegir de
    // cual quincena se extiende el voucher de pago.
    public function vouchersDisponibles($empleadoId)
    {
        Empleado::findOrFail($empleadoId);

        $planillas = CabeceraPlanilla::where('estado', 'Cerrado')
            ->whereHas('detalles', fn($q) => $q->where('id_empleado', $empleadoId))
            ->orderByDesc('fecha_generada')
            ->get(['id', 'nombre_planilla', 'tipo_planilla', 'fecha_generada']);

        return response()->json($planillas);
    }

    public function voucher($empleadoId, $planillaId)
    {
        CabeceraPlanilla::where('estado', 'Cerrado')->findOrFail($planillaId);

        $detalle = DetallePlanilla::where('id_empleado', $empleadoId)
            ->where('id_cabecera_planilla', $planillaId)
            ->with('empleado:id,nombres,apellidos,cedula,id_cargo,id_departamento')
            ->firstOrFail();

        $correlativo = $this->siguienteCorrelativo('voucher', $detalle->id);

        $pdf = Pdf::loadView('constancias.voucher', compact('detalle', 'correlativo'))
            ->setPaper('letter', 'portrait');

        $nombrePlanilla = $this->sanitizarNombreArchivo($detalle->nombre_planilla);
        $nombreEmpleado = $this->sanitizarNombreArchivo("{$detalle->empleado->nombres} {$detalle->empleado->apellidos}");
        $archivo = "{$nombrePlanilla}_{$nombreEmpleado}.pdf";

        $this->logActividad(
            'generado',
            'Constancias',
            "Voucher de pago emitido para {$detalle->empleado->nombres} {$detalle->empleado->apellidos} ({$detalle->nombre_planilla}).",
            $empleadoId
        );

        return $pdf->download($archivo)
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->header('Pragma', 'no-cache');
    }
}
