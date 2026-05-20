<?php

namespace App\Http\Controllers;

use App\Models\Empleado;
use App\Models\SolicitudVacacion;
use App\Models\Vacacion;
use App\Traits\LogsActividad;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Http\Request;

class VacacionController extends Controller
{
    use LogsActividad;
    // ── Helpers ──────────────────────────────────────────────────────────────

    private function diasLaborables(Carbon $inicio, Carbon $fin): int
    {
        $dias    = 0;
        $current = $inicio->copy()->startOfDay();
        $fin     = $fin->copy()->startOfDay();

        while ($current->lte($fin)) {
            if ($current->dayOfWeek !== Carbon::SUNDAY) {
                $dias++;
            }
            $current->addDay();
        }

        return $dias;
    }

    private function tasasDias(): array
    {
        $cfg = Vacacion::first();
        return [
            1 => (float) ($cfg->primer_anio          ?? 10),
            2 => (float) ($cfg->segundo_anio          ?? 12),
            3 => (float) ($cfg->tercer_anio           ?? 15),
            4 => (float) ($cfg->cuarto_anio_adelante  ?? 20),
        ];
    }

    private function calcularSaldo(Empleado $empleado): array
    {
        $il = $empleado->informacionLaboral;

        if (!$il || !$il->fecha_inicio) {
            return [
                'anios_laborados' => 0, 'dias_por_ley' => 0,
                'dias_tomados' => 0,    'saldo' => 0,
                'periodo_inicio' => null, 'periodo_fin' => null,
                'sin_fecha_inicio' => true,
            ];
        }

        $inicio = Carbon::parse($il->fecha_inicio)->startOfDay();
        $hoy    = Carbon::today();

        if ($hoy->lt($inicio)) {
            return [
                'anios_laborados' => 0, 'dias_por_ley' => 0,
                'dias_tomados' => 0,    'saldo' => 0,
                'periodo_inicio' => null, 'periodo_fin' => null,
            ];
        }

        $anios = (int) floor($inicio->diffInDays($hoy) / 365);
        $tasas = $this->tasasDias();

        $diasPorLey = match(true) {
            $anios >= 4 => $tasas[4],
            $anios === 3 => $tasas[3],
            $anios === 2 => $tasas[2],
            $anios >= 1 => $tasas[1],
            default     => 0,
        };

        // Período: último aniversario → siguiente aniversario
        $aniversario = $inicio->copy()->year($hoy->year);
        if ($aniversario->isAfter($hoy)) $aniversario->subYear();
        $periodoInicio = $aniversario->copy();
        $periodoFin    = $aniversario->copy()->addYear()->subDay();

        $diasTomados = SolicitudVacacion::where('id_empleado', $empleado->id)
            ->where('fecha_inicio', '>=', $periodoInicio)
            ->where('fecha_inicio', '<=', $periodoFin)
            ->sum('dias_tomados');

        return [
            'anios_laborados' => $anios,
            'dias_por_ley'    => $diasPorLey,
            'dias_tomados'    => (float) $diasTomados,
            'saldo'           => max(0, $diasPorLey - $diasTomados),
            'periodo_inicio'  => $periodoInicio->format('Y-m-d'),
            'periodo_fin'     => $periodoFin->format('Y-m-d'),
        ];
    }

    // ── Endpoints ─────────────────────────────────────────────────────────────

    public function index(Request $request)
    {
        $solicitudes = SolicitudVacacion::with('empleado:id,nombres,apellidos,id_puesto,id_departamento')
            ->when($request->id_empleado, fn($q, $id) => $q->where('id_empleado', $id))
            ->when($request->search, fn($q, $s) =>
                $q->whereHas('empleado', fn($eq) =>
                    $eq->where('nombres', 'like', "%$s%")
                       ->orWhere('apellidos', 'like', "%$s%")
                )
            )
            ->orderByDesc('fecha_inicio')
            ->paginate(15);

        return response()->json($solicitudes);
    }

    public function saldo($id)
    {
        $empleado = Empleado::with(['informacionLaboral', 'puesto', 'departamento'])
            ->findOrFail($id);

        return response()->json([
            'empleado' => $empleado,
            'saldo'    => $this->calcularSaldo($empleado),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'id_empleado'  => 'required|exists:empleados,id',
            'fecha_inicio' => 'required|date',
            'fecha_fin'    => 'required|date|after_or_equal:fecha_inicio',
            'observaciones'=> 'nullable|string|max:500',
        ]);

        $inicio = Carbon::parse($data['fecha_inicio']);
        $fin    = Carbon::parse($data['fecha_fin']);
        $dias   = $this->diasLaborables($inicio, $fin);

        $empleado = Empleado::with('informacionLaboral')->findOrFail($data['id_empleado']);
        $saldo    = $this->calcularSaldo($empleado);

        if ($saldo['saldo'] < $dias) {
            return response()->json([
                'message' => "Saldo insuficiente. Disponibles: {$saldo['saldo']} día(s), solicitados: {$dias}.",
            ], 422);
        }

        $solicitud = SolicitudVacacion::create([
            ...$data,
            'dias_tomados' => $dias,
            'id_usuario'   => $request->user()->id,
        ]);

        $solicitud->load('empleado:id,nombres,apellidos');
        $this->logActividad('creado', 'Vacaciones', "Solicitud de {$dias} día(s) para {$solicitud->empleado->nombres} {$solicitud->empleado->apellidos}.", $solicitud->id);

        return response()->json($solicitud, 201);
    }

    public function update(Request $request, $id)
    {
        $solicitud = SolicitudVacacion::findOrFail($id);

        $data = $request->validate([
            'fecha_inicio'  => 'required|date',
            'fecha_fin'     => 'required|date|after_or_equal:fecha_inicio',
            'observaciones' => 'nullable|string|max:500',
        ]);

        $inicio = Carbon::parse($data['fecha_inicio']);
        $fin    = Carbon::parse($data['fecha_fin']);
        $dias   = $this->diasLaborables($inicio, $fin);

        $solicitud->update([...$data, 'dias_tomados' => $dias]);

        return response()->json($solicitud->fresh(['empleado:id,nombres,apellidos']));
    }

    public function destroy($id)
    {
        $sol = SolicitudVacacion::with('empleado:id,nombres,apellidos')->findOrFail($id);
        $sol->delete();
        $this->logActividad('eliminado', 'Vacaciones', "Solicitud de vacaciones de {$sol->empleado->nombres} {$sol->empleado->apellidos} eliminada.", $id);

        return response()->json(['message' => 'Solicitud eliminada.']);
    }

    public function pdf($id)
    {
        $solicitud = SolicitudVacacion::with([
            'empleado.informacionLaboral.banco',
            'empleado.puesto',
            'empleado.departamento',
        ])->findOrFail($id);

        $saldo = $this->calcularSaldo($solicitud->empleado);

        $pdf = Pdf::loadView('vacaciones.solicitud', compact('solicitud', 'saldo'))
            ->setPaper('letter', 'portrait');

        $nombres   = $solicitud->empleado->nombres;
        $apellidos = $solicitud->empleado->apellidos;
        $n = iconv('UTF-8', 'ASCII//TRANSLIT', "{$nombres}+{$apellidos}") ?? "{$nombres}+{$apellidos}";
        $n = preg_replace('/[^a-zA-Z0-9+\-]/', '', str_replace(' ', '', $n));

        return $pdf->download(now()->format('dmY') . '-' . $n . '-vacaciones.pdf');
    }
}
