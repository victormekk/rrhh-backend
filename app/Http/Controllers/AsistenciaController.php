<?php

namespace App\Http\Controllers;

use App\Models\Empleado;
use App\Models\Marcacion;
use App\Traits\LogsActividad;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AsistenciaController extends Controller
{
    use LogsActividad;

    // Recibe las marcaciones que el agente local (zkteco-agente/) leyo del
    // reloj biometrico y las sube por HTTPS. No usa auth:sanctum (el agente
    // corre desatendido, sin sesion de usuario) sino un token compartido
    // simple, ver bootstrap/app.php o routes/api.php.
    public function importar(Request $request)
    {
        $data = $request->validate([
            'marcaciones'                    => 'required|array|min:1',
            'marcaciones.*.codigo_biometrico'=> 'required|string|max:20',
            'marcaciones.*.fecha'            => 'required|date',
            'marcaciones.*.hora'             => 'required|date_format:H:i:s',
            'marcaciones.*.device_ip'        => 'nullable|string|max:45',
        ]);

        $codigos = collect($data['marcaciones'])->pluck('codigo_biometrico')->unique();
        $empleadoPorCodigo = Empleado::whereIn('codigo_biometrico', $codigos)
            ->pluck('id', 'codigo_biometrico');

        $insertadas = 0;
        $sinMapear  = [];

        DB::transaction(function () use ($data, $empleadoPorCodigo, &$insertadas, &$sinMapear) {
            foreach ($data['marcaciones'] as $m) {
                $idEmpleado = $empleadoPorCodigo->get($m['codigo_biometrico']);
                if (!$idEmpleado) {
                    $sinMapear[$m['codigo_biometrico']] = true;
                }

                // updateOrCreate sobre la clave unica evita duplicados si el
                // agente reenvia una ventana de tiempo ya sincronizada.
                $creada = Marcacion::firstOrCreate(
                    [
                        'codigo_biometrico' => $m['codigo_biometrico'],
                        'fecha'             => $m['fecha'],
                        'hora'              => $m['hora'],
                    ],
                    [
                        'id_empleado' => $idEmpleado,
                        'device_ip'   => $m['device_ip'] ?? null,
                    ]
                );

                if ($creada->wasRecentlyCreated) {
                    $insertadas++;
                }
            }
        });

        $this->logActividad(
            'generado',
            'Asistencia',
            "Importadas {$insertadas} marcación(es) biométrica(s)" .
                (count($sinMapear) ? ' — códigos sin empleado asignado: ' . implode(', ', array_keys($sinMapear)) : '.'),
            null
        );

        return response()->json([
            'recibidas'   => count($data['marcaciones']),
            'insertadas'  => $insertadas,
            'sin_mapear'  => array_keys($sinMapear),
        ], 201);
    }

    // Resumen de dias con marcaciones para un empleado en un rango de fechas,
    // usado por la pantalla de Crear Planilla para previsualizar antes de
    // generar (y por PlanillaController al aplicar el conteo automatico).
    public function resumen(Request $request)
    {
        $data = $request->validate([
            'id_empleado' => 'required|exists:empleados,id',
            'desde'       => 'required|date',
            'hasta'       => 'required|date|after_or_equal:desde',
        ]);

        $dias = Marcacion::where('id_empleado', $data['id_empleado'])
            ->whereBetween('fecha', [$data['desde'], $data['hasta']])
            ->select('fecha', DB::raw('COUNT(*) as marcas'))
            ->groupBy('fecha')
            ->orderBy('fecha')
            ->get();

        return response()->json([
            'dias_trabajados' => $dias->where('marcas', '>=', 2)->count(),
            'detalle'         => $dias,
        ]);
    }
}
