<?php

namespace App\Http\Controllers;

use App\Calculos\CalculoVacaciones;
use App\Calculos\Feriados;
use App\Models\Empleado;
use App\Models\SolicitudVacacion;
use App\Models\Vacacion;
use App\Traits\GeneraCorrelativo;
use App\Traits\LogsActividad;
use App\Traits\NombraArchivos;
use App\Traits\SoloAdmin;
use App\Traits\TieneFeriados;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Http\Request;

class VacacionController extends Controller
{
    use LogsActividad, NombraArchivos, GeneraCorrelativo, SoloAdmin, TieneFeriados;
    // ── Helpers ──────────────────────────────────────────────────────────────

    // La lógica de días laborables y saldo vive en App\Calculos\CalculoVacaciones
    // (probada en tests/Unit/CalculoVacacionesTest.php); aquí solo se consulta la BD.
    private function diasLaborables(Carbon $inicio, Carbon $fin): array
    {
        return CalculoVacaciones::diasLaborables($inicio, $fin);
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

        $zeroBase = [
            'anios_laborados'     => 0, 'dias_por_ley'         => 0,
            'dias_anio_actual'    => 0, 'dias_previos'         => 0,
            'dias_tomados'        => 0, 'dias_tomados_periodo' => 0,
            'saldo'               => 0, 'periodo_inicio'       => null,
            'periodo_fin'         => null,
        ];

        if (!$il || !$il->fecha_inicio) {
            return array_merge($zeroBase, ['sin_fecha_inicio' => true]);
        }

        $inicio = Carbon::parse($il->fecha_inicio)->startOfDay();
        $hoy    = Carbon::today();

        if ($hoy->lt($inicio)) {
            return $zeroBase;
        }

        $calculo = new CalculoVacaciones($this->tasasDias());
        [$periodoInicio] = $calculo->periodo($inicio, $hoy);

        // Una sola query con SUM condicional en lugar de dos queries separadas
        $tomados = SolicitudVacacion::where('id_empleado', $empleado->id)
            ->selectRaw(
                'SUM(dias_tomados) as total, SUM(CASE WHEN fecha_inicio >= ? THEN dias_tomados ELSE 0 END) as periodo',
                [$periodoInicio->format('Y-m-d')]
            )->first();

        return $calculo->saldo($inicio, $hoy, (float) ($tomados->total ?? 0), (float) ($tomados->periodo ?? 0));
    }

    // ── Endpoints ─────────────────────────────────────────────────────────────

    public function index(Request $request)
    {
        $solicitudes = SolicitudVacacion::with('empleado:id,nombres,apellidos,id_cargo,id_departamento')
            ->when($request->id_empleado, fn($q, $id) => $q->where('id_empleado', $id))
            ->when($request->search, fn($q, $s) =>
                $q->whereHas('empleado', fn($eq) =>
                    $eq->where('nombres', 'like', "%$s%")
                       ->orWhere('apellidos', 'like', "%$s%")
                )
            )
            ->orderByDesc('fecha_inicio')
            ->paginate($request->input('per_page', 10));

        return response()->json($solicitudes);
    }

    public function saldo($id)
    {
        $empleado = Empleado::with(['informacionLaboral', 'cargo', 'departamento'])
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

        $inicio  = Carbon::parse($data['fecha_inicio']);
        $fin     = Carbon::parse($data['fecha_fin']);
        $calculo = $this->diasLaborables($inicio, $fin);
        $dias    = $calculo['dias'];

        $empleado = Empleado::with('informacionLaboral')->findOrFail($data['id_empleado']);
        $saldo    = $this->calcularSaldo($empleado);

        if ($saldo['saldo'] < $dias) {
            return response()->json([
                'message' => "No hay suficientes días disponibles. Disponibles: {$saldo['saldo']} día(s), solicitados: {$dias}.",
            ], 422);
        }

        $solicitud = SolicitudVacacion::create([
            ...$data,
            'dias_tomados' => $dias,
            'id_usuario'   => $request->user()->id,
        ]);

        $solicitud->load('empleado:id,nombres,apellidos');
        $this->logActividad('creado', 'Vacaciones', "Solicitud de {$dias} día(s) para {$solicitud->empleado->nombres} {$solicitud->empleado->apellidos}.", $solicitud->id);

        $solicitud->setAttribute('feriados_excluidos', $calculo['feriados']);

        return response()->json($solicitud, 201);
    }

    public function update(Request $request, $id)
    {
        $solicitud = SolicitudVacacion::with('empleado.informacionLaboral')->findOrFail($id);

        $data = $request->validate([
            'fecha_inicio'  => 'required|date',
            'fecha_fin'     => 'required|date|after_or_equal:fecha_inicio',
            'observaciones' => 'nullable|string|max:500',
        ]);

        $inicio  = Carbon::parse($data['fecha_inicio']);
        $fin     = Carbon::parse($data['fecha_fin']);
        $calculo = $this->diasLaborables($inicio, $fin);
        $dias    = $calculo['dias'];

        // Saldo efectivo: sumar de vuelta los días originales antes de comparar
        $saldo         = $this->calcularSaldo($solicitud->empleado);
        $saldoEfectivo = $saldo['saldo'] + $solicitud->dias_tomados;

        if ($saldoEfectivo < $dias) {
            return response()->json([
                'message' => "No hay suficientes días disponibles. Disponibles: {$saldoEfectivo} día(s), solicitados: {$dias}.",
            ], 422);
        }

        $solicitud->update([...$data, 'dias_tomados' => $dias]);

        $fresco = $solicitud->fresh(['empleado:id,nombres,apellidos']);
        $fresco->setAttribute('feriados_excluidos', $calculo['feriados']);

        return response()->json($fresco);
    }

    public function destroy(Request $request, $id)
    {
        $this->soloAdmin($request);

        $sol = SolicitudVacacion::with('empleado:id,nombres,apellidos')->findOrFail($id);
        $sol->delete();
        $this->logActividad('eliminado', 'Vacaciones', "Solicitud de vacaciones de {$sol->empleado->nombres} {$sol->empleado->apellidos} eliminada.", $id);

        return response()->json(['message' => 'Solicitud eliminada.']);
    }

    public function pdf($id)
    {
        $solicitud = SolicitudVacacion::with([
            'empleado.informacionLaboral.banco',
            'empleado.cargo',
            'empleado.departamento',
        ])->findOrFail($id);

        $saldo = $this->calcularSaldo($solicitud->empleado);
        $correlativo = $this->siguienteCorrelativo('vacacion', $solicitud->id);

        $solicitudAnterior = SolicitudVacacion::where('id_empleado', $solicitud->id_empleado)
            ->where(function ($q) use ($solicitud) {
                $q->where('fecha_inicio', '<', $solicitud->fecha_inicio)
                    ->orWhere(function ($q2) use ($solicitud) {
                        $q2->where('fecha_inicio', $solicitud->fecha_inicio)->where('id', '<', $solicitud->id);
                    });
            })
            ->orderByDesc('fecha_inicio')
            ->orderByDesc('id')
            ->first();

        // Fecha de reintegro: el siguiente día hábil despues del ultimo dia de
        // vacaciones, saltando domingos y feriados nacionales.
        $retorno = Feriados::siguienteDiaHabil(Carbon::parse($solicitud->fecha_fin));

        $pdf = Pdf::loadView('vacaciones.solicitud', compact('solicitud', 'saldo', 'correlativo', 'solicitudAnterior', 'retorno'))
            ->setPaper('letter', 'portrait');

        $nombres   = $solicitud->empleado->nombres;
        $apellidos = $solicitud->empleado->apellidos;

        return $pdf->download($this->nombreArchivo('Vacaciones', "{$nombres} {$apellidos}", 'pdf'));
    }
}
