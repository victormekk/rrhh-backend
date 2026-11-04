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
use App\Traits\TieneFeriados;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class VacacionController extends Controller
{
    use LogsActividad, NombraArchivos, GeneraCorrelativo, TieneFeriados;
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

    // $excluirId: solicitud que no se cuenta (al editarla, sus días vuelven al saldo).
    private function calcularSaldo(Empleado $empleado, ?int $excluirId = null): array
    {
        $il = $empleado->informacionLaboral;

        $zeroBase = [
            'anios_laborados'     => 0, 'dias_por_ley'         => 0,
            'dias_anio_actual'    => 0, 'dias_previos'         => 0,
            'dias_tomados'        => 0, 'dias_tomados_periodo' => 0,
            'saldo'               => 0, 'periodo_inicio'       => null,
            'periodo_fin'         => null,
            'dias_adelantados'    => 0, 'dias_proximo_periodo' => 0,
            'proximo_periodo_inicio' => null, 'adelanto_disponible' => 0,
            'maximo_solicitable'  => 0,
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
            ->when($excluirId, fn ($q) => $q->where('id', '!=', $excluirId))
            ->selectRaw(
                'SUM(dias_tomados) as total, SUM(CASE WHEN fecha_inicio >= ? THEN dias_tomados ELSE 0 END) as periodo',
                [$periodoInicio->format('Y-m-d')]
            )->first();

        return $calculo->saldo($inicio, $hoy, (float) ($tomados->total ?? 0), (float) ($tomados->periodo ?? 0));
    }

    // Revisa si alcanzan los días. Mientras tenga días disponibles, la solicitud solo
    // puede usar esos días (como siempre). Solo con el saldo en cero se puede adelantar:
    // hasta los días del próximo período que aún no haya adelantado, y con $adelantar
    // confirmado (botón "Adelantar vacaciones"). Devuelve [error o null, días adelantados].
    private function validarDias(array $saldo, float $dias, bool $adelantar): array
    {
        $disponible = (float) $saldo['saldo'];
        if ($disponible > 0) {
            if ($dias > $disponible) {
                return [response()->json([
                    'message' => "No hay suficientes días disponibles. Disponibles: {$disponible} día(s), solicitados: {$dias}.",
                ], 422), 0];
            }

            return [null, 0];
        }

        $adelanto = (float) $saldo['adelanto_disponible'];
        if ($adelanto <= 0) {
            return [response()->json([
                'message' => 'No tiene días disponibles y ya adelantó todos los días de su próximo período.',
            ], 422), 0];
        }
        if ($dias > $adelanto) {
            return [response()->json([
                'message' => "Se pueden adelantar como máximo {$adelanto} día(s) del próximo período. Solicitados: {$dias}.",
            ], 422), 0];
        }
        if (!$adelantar) {
            return [response()->json([
                'message'          => "No tiene días disponibles: se adelantarían {$dias} día(s) del próximo período. Confirme el adelanto para continuar.",
                'codigo'           => 'requiere_adelanto',
                'dias_adelantados' => $dias,
            ], 422), 0];
        }

        return [null, $dias];
    }

    // Fechas permitidas: desde 1990 hasta 10 años después del actual (el límite de
    // arriba avanza solo cada año). Evita contar días de años a medio escribir (0002,
    // 0202...) o mal escritos (2206), que dejaban el cálculo ocupado por mucho tiempo.
    private function reglasAnio(): array
    {
        $hasta = now()->addYears(10)->endOfYear()->toDateString();

        return ['after_or_equal:1990-01-01', "before_or_equal:{$hasta}"];
    }

    // ── Endpoints ─────────────────────────────────────────────────────────────

    public function index(Request $request)
    {
        $anuladas = $request->boolean('anuladas');

        $solicitudes = SolicitudVacacion::with('empleado:id,nombres,apellidos,id_cargo,id_departamento')
            ->when($anuladas, fn($q) => $q->onlyTrashed()->with('usuarioAnula:id,name')->orderByDesc('anulada_at'))
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
            'fecha_inicio' => ['required', 'date', ...$this->reglasAnio()],
            'fecha_fin'    => ['required', 'date', 'after_or_equal:fecha_inicio', ...$this->reglasAnio()],
            'observaciones'=> 'nullable|string|max:500',
            'adelantar'    => 'boolean',
        ]);
        unset($data['adelantar']);

        $inicio  = Carbon::parse($data['fecha_inicio']);
        $fin     = Carbon::parse($data['fecha_fin']);
        $calculo = $this->diasLaborables($inicio, $fin);
        $dias    = $calculo['dias'];

        $empleado = Empleado::with('informacionLaboral')->findOrFail($data['id_empleado']);
        $saldo    = $this->calcularSaldo($empleado);

        [$error, $adelantados] = $this->validarDias($saldo, $dias, $request->boolean('adelantar'));
        if ($error) {
            return $error;
        }

        $solicitud = SolicitudVacacion::create([
            ...$data,
            'dias_tomados'     => $dias,
            'dias_adelantados' => $adelantados,
            'id_usuario'       => $request->user()->id,
        ]);

        $solicitud->load('empleado:id,nombres,apellidos');
        $adelanto = $adelantados > 0
            ? " ({$adelantados} adelantado(s) del período que inicia el " . Carbon::parse($saldo['proximo_periodo_inicio'])->format('d/m/Y') . ')'
            : '';
        $this->logActividad('creado', 'Vacaciones', "Solicitud de {$dias} día(s) para {$solicitud->empleado->nombres} {$solicitud->empleado->apellidos}{$adelanto}.", $solicitud->id);

        $solicitud->setAttribute('feriados_excluidos', $calculo['feriados']);

        return response()->json($solicitud, 201);
    }

    public function update(Request $request, $id)
    {
        $solicitud = SolicitudVacacion::with('empleado.informacionLaboral')->findOrFail($id);

        $data = $request->validate([
            'fecha_inicio'  => ['required', 'date', ...$this->reglasAnio()],
            'fecha_fin'     => ['required', 'date', 'after_or_equal:fecha_inicio', ...$this->reglasAnio()],
            'observaciones' => 'nullable|string|max:500',
            'adelantar'     => 'boolean',
        ]);
        unset($data['adelantar']);

        $inicio  = Carbon::parse($data['fecha_inicio']);
        $fin     = Carbon::parse($data['fecha_fin']);
        $calculo = $this->diasLaborables($inicio, $fin);
        $dias    = $calculo['dias'];

        // Saldo sin contar esta misma solicitud: sus días vuelven antes de comparar.
        $saldo = $this->calcularSaldo($solicitud->empleado, $solicitud->id);

        [$error, $adelantados] = $this->validarDias($saldo, $dias, $request->boolean('adelantar'));
        if ($error) {
            return $error;
        }

        $solicitud->update([...$data, 'dias_tomados' => $dias, 'dias_adelantados' => $adelantados]);

        $fresco = $solicitud->fresh(['empleado:id,nombres,apellidos']);
        $fresco->setAttribute('feriados_excluidos', $calculo['feriados']);

        return response()->json($fresco);
    }

    // No borra la fila: la anula (borrado lógico) guardando quién, cuándo y por qué.
    // Los días vuelven al saldo porque calcularSaldo ignora las anuladas.
    public function destroy(Request $request, $id)
    {
        $data = $request->validate([
            'motivo' => 'required|string|max:300',
        ]);

        $sol = SolicitudVacacion::with('empleado:id,nombres,apellidos')->findOrFail($id);

        DB::transaction(function () use ($sol, $data, $request) {
            $sol->forceFill([
                'anulada_por'      => $request->user()->id,
                'motivo_anulacion' => $data['motivo'],
            ])->save();
            $sol->delete();
        });

        $this->logActividad('anulado', 'Vacaciones', "Solicitud de vacaciones de {$sol->empleado->nombres} {$sol->empleado->apellidos} (" . (float) $sol->dias_tomados . " día(s)) anulada. Motivo: {$data['motivo']}", $sol->id);

        return response()->json(['message' => 'Solicitud anulada.']);
    }

    // Restaurar vuelve a descontar los días, así que primero revisa el saldo.
    public function restaurar(Request $request, $id)
    {
        $sol = SolicitudVacacion::onlyTrashed()->with('empleado.informacionLaboral')->findOrFail($id);

        // Mismas reglas que al crearla: con saldo, debe caber en el saldo; con el saldo en
        // cero, se restaura como adelanto (restaurarla ya es la confirmación).
        $saldo = $this->calcularSaldo($sol->empleado);
        [$error, $adelantados] = $this->validarDias($saldo, (float) $sol->dias_tomados, true);
        if ($error) {
            return $error;
        }

        DB::transaction(function () use ($sol, $adelantados) {
            $sol->restore();
            $sol->forceFill(['anulada_por' => null, 'motivo_anulacion' => null, 'dias_adelantados' => $adelantados])->save();
        });

        $this->logActividad('editado', 'Vacaciones', "Solicitud de vacaciones de {$sol->empleado->nombres} {$sol->empleado->apellidos} restaurada.", $sol->id);

        return response()->json(['message' => 'Solicitud restaurada.']);
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

        // Adelanto: los días se tomaron del período que empieza en el aniversario
        // siguiente a la fecha en que se registró la solicitud.
        $periodoAdelanto = null;
        $il = $solicitud->empleado->informacionLaboral;
        if ((float) $solicitud->dias_adelantados > 0 && $il?->fecha_inicio) {
            [, $finPeriodo] = (new CalculoVacaciones($this->tasasDias()))
                ->periodo(Carbon::parse($il->fecha_inicio), Carbon::parse($solicitud->created_at));
            $periodoAdelanto = $finPeriodo->copy()->addDay();
        }

        $pdf = Pdf::loadView('vacaciones.solicitud', compact('solicitud', 'saldo', 'correlativo', 'solicitudAnterior', 'retorno', 'periodoAdelanto'))
            ->setPaper('letter', 'portrait');

        $nombres   = $solicitud->empleado->nombres;
        $apellidos = $solicitud->empleado->apellidos;

        return $pdf->download($this->nombreArchivo('Vacaciones', "{$nombres} {$apellidos}", 'pdf'));
    }
}
