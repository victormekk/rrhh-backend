<?php

namespace App\Http\Controllers;

use App\Models\Empleado;
use App\Traits\LogsActividad;
use App\Traits\NombraArchivos;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;

class ConstanciaController extends Controller
{
    use LogsActividad, NombraArchivos;

    private const MESES = [
        'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio',
        'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre',
    ];

    public function laboral($id)
    {
        $emp = Empleado::with(['informacionLaboral', 'cargo', 'departamento'])->findOrFail($id);

        $il = $emp->informacionLaboral;
        abort_if(!$il || !$il->fecha_inicio, 422, 'El empleado no tiene información laboral registrada.');

        $fechaInicio    = Carbon::parse($il->fecha_inicio);
        $salarioMensual = (float) $il->salario_base;
        $simboloMoneda  = $il->moneda === 'Dólares' ? 'US$' : 'L.';
        $meses          = self::MESES;

        $pdf = Pdf::loadView('constancias.laboral', compact(
            'emp', 'fechaInicio', 'salarioMensual', 'simboloMoneda', 'meses'
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
}
