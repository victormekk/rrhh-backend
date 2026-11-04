<?php

namespace App\Http\Controllers;

use App\Calculos\Fechas;
use App\Models\Empleado;
use App\Pdf\AvatarCircular;
use App\Pdf\DecoracionCumpleanos;
use App\Traits\LogsActividad;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class CumpleanosController extends Controller
{
    use LogsActividad;

    private const MESES = [
        1 => 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio',
        'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre',
    ];

    public function index(Request $request)
    {
        return response()->json($this->cumpleaneros($this->mes($request)));
    }

    // Colores de las iniciales, los mismos de la pantalla de Cumpleaños (colorAvatar).
    private const COLORES = ['#b9921a', '#10b981', '#8b5cf6', '#f43f5e', '#f59e0b', '#06b6d4', '#ec4899', '#6366f1'];

    // PDF con la misma lista de la pantalla: tarjetas con foto o iniciales, nombre,
    // departamento y fecha, por día de cumpleaños. No lleva la edad (hay empleados
    // que prefieren que no se muestre) ni correlativo.
    public function pdf(Request $request)
    {
        $mes       = $this->mes($request);
        $nombreMes = self::MESES[$mes];
        $base      = $this->cumpleaneros($mes);
        $fotos     = Empleado::whereIn('id', $base->pluck('id'))->pluck('foto_path', 'id');

        $lista = $base->map(function (array $emp) use ($fotos) {
            $ruta    = $fotos[$emp['id']] ?? null;
            $archivo = $ruta ? storage_path('app/public/' . $ruta) : null;
            $color   = self::COLORES[$emp['id'] % count(self::COLORES)];
            $iniciales = mb_substr($emp['nombres'] ?? '', 0, 1) . mb_substr($emp['apellidos'] ?? '', 0, 1);

            return [
                ...$emp,
                'color'   => $color,
                'globito' => DecoracionCumpleanos::globito($color),
                'avatar' => ($archivo && is_file($archivo) ? AvatarCircular::foto($archivo) : null)
                    ?? AvatarCircular::iniciales($iniciales, $color),
            ];
        });

        $this->logActividad('generado', 'Empleados', "Exportó los cumpleaños de {$nombreMes} a PDF ({$lista->count()} empleado(s)).");

        // Adornos: se publica en cada departamento, por eso lleva globos, banderines y confeti.
        $deco = [
            'banderines' => DecoracionCumpleanos::banderines(),
            'globosIzq'  => DecoracionCumpleanos::globos(['#c0101a', '#f5d92a', '#8b5cf6']),
            'globosDer'  => DecoracionCumpleanos::globos(['#06b6d4', '#e8892f', '#ec4899']),
            'confeti'    => DecoracionCumpleanos::confeti(),
        ];

        return Pdf::loadView('cumpleanos.pdf', compact('lista', 'nombreMes', 'deco'))
            ->setPaper('letter', 'portrait')
            ->download("Cumpleanos_{$nombreMes}_" . now()->format('dmY') . '.pdf');
    }

    private function mes(Request $request): int
    {
        $mes = (int) ($request->mes ?? Carbon::now()->month);

        return $mes >= 1 && $mes <= 12 ? $mes : Carbon::now()->month;
    }

    private function cumpleaneros(int $mes): Collection
    {
        $hoy = Carbon::now();

        return Empleado::with(['departamento:id,nombre'])
            ->whereNotNull('fecha_nacimiento')
            ->whereHas('informacionLaboral', fn($q) => $q->where('estado', 'Activo'))
            ->whereRaw('MONTH(fecha_nacimiento) = ?', [$mes])
            ->orderByRaw('DAY(fecha_nacimiento)')
            ->get()
            ->map(function ($emp) use ($hoy) {
                $nac = $emp->fecha_nacimiento;
                // Quien nació un 29 de febrero celebra el 28 en años no bisiestos.
                $cumpleEsteAnio = Fechas::aniversarioEn($nac, $hoy->year);
                $esHoy = $cumpleEsteAnio->isSameDay($hoy);
                $edad  = $hoy->year - $nac->year;

                $proxCumple = $cumpleEsteAnio->copy();
                if ($proxCumple->lt($hoy->copy()->startOfDay()) && !$esHoy) {
                    $proxCumple = Fechas::aniversarioEn($nac, $hoy->year + 1);
                }
                $diasPara = $esHoy ? 0 : (int) $hoy->copy()->startOfDay()->diffInDays($proxCumple);

                return [
                    'id'               => $emp->id,
                    'nombres'          => $emp->nombres,
                    'apellidos'        => $emp->apellidos,
                    'genero'           => $emp->genero,
                    'foto_url'         => $emp->foto_url,
                    'departamento'     => $emp->departamento?->nombre ?? '—',
                    'fecha_nacimiento' => $nac->format('Y-m-d'),
                    'fecha_celebracion' => $cumpleEsteAnio->format('Y-m-d'),
                    'dia'              => $nac->day,
                    'edad_cumple'      => $edad,
                    'es_hoy'           => $esHoy,
                    'dias_para'        => $diasPara,
                ];
            });
    }
}
