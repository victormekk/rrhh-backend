<?php

namespace App\Http\Controllers;

use App\Models\Empleado;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;

class ConstanciaController extends Controller
{
    private const MESES = [
        'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio',
        'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre',
    ];

    public function laboral($id)
    {
        $emp = Empleado::with(['informacionLaboral', 'puesto', 'departamento'])->findOrFail($id);

        $il = $emp->informacionLaboral;
        abort_if(!$il || !$il->fecha_inicio, 422, 'El empleado no tiene información laboral registrada.');

        $fechaInicio    = Carbon::parse($il->fecha_inicio);
        $salarioMensual = (float) $il->salario_base;
        $simboloMoneda  = $il->moneda === 'Dólares' ? 'US$' : 'L.';
        $meses          = self::MESES;

        $pdf = Pdf::loadView('constancias.laboral', compact(
            'emp', 'fechaInicio', 'salarioMensual', 'simboloMoneda', 'meses'
        ))->setPaper('letter', 'portrait');

        $n = iconv('UTF-8', 'ASCII//TRANSLIT', "{$emp->nombres}+{$emp->apellidos}") ?? "{$emp->nombres}+{$emp->apellidos}";
        $n = preg_replace('/[^a-zA-Z0-9+\-]/', '', str_replace(' ', '', $n));

        return $pdf->download(now()->format('dmY') . '-' . $n . '-constancia-laboral.pdf')
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->header('Pragma', 'no-cache');
    }
}
