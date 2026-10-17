<?php

namespace App\Http\Controllers;

use App\Models\Empleado;
use App\Models\Marcacion;
use App\Traits\LogsActividad;
use App\Traits\SoloAdmin;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AsistenciaController extends Controller
{
    use LogsActividad, SoloAdmin;

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

    // Tipos de marca del reloj ZKTeco (campo "punch").
    private const TIPOS_PUNCH = [
        '0' => 'Entrada', '1' => 'Salida', '2' => 'Salida descanso',
        '3' => 'Regreso descanso', '4' => 'Entrada extra', '5' => 'Salida extra',
    ];

    // Columnas aceptadas en el encabezado del CSV (sin tildes, en minúscula).
    private const COLUMNAS = [
        'codigo'     => ['codigo', 'codigo_biometrico', 'user_id', 'codigo_reloj'],
        'fecha_hora' => ['fecha_hora', 'timestamp'],
        'fecha'      => ['fecha'],
        'hora'       => ['hora'],
        'tipo'       => ['tipo', 'punch'],
        'reloj'      => ['reloj', 'device_ip', 'ip'],
    ];

    // "Cargar Marcas": recibe el CSV con las marcas descargadas de los relojes
    // y las guarda. Las marcas repetidas (mismo código, fecha y hora) se
    // ignoran, así que se puede volver a subir el mismo período sin duplicar.
    public function cargarCsv(Request $request)
    {
        $request->validate([
            'archivo' => 'required|file|extensions:csv,txt|max:20480',
        ], [
            'archivo.extensions' => 'El archivo debe ser .csv',
            'archivo.max'        => 'El archivo no puede pesar más de 20 MB.',
        ]);

        $fh = fopen($request->file('archivo')->getRealPath(), 'r');
        $primera = fgets($fh);
        $separador = substr_count($primera, ';') > substr_count($primera, ',') ? ';' : ',';
        $primera = preg_replace('/^\xEF\xBB\xBF/', '', $primera); // BOM de Excel

        $encabezado = array_map(fn ($c) => $this->normalizar($c), str_getcsv($primera, $separador));
        $col = [];
        foreach (self::COLUMNAS as $campo => $nombres) {
            foreach ($nombres as $n) {
                $i = array_search($n, $encabezado, true);
                if ($i !== false) { $col[$campo] = $i; break; }
            }
        }
        if (!isset($col['codigo']) || !(isset($col['fecha_hora']) || isset($col['fecha'], $col['hora']))) {
            fclose($fh);
            return response()->json([
                'message' => 'El CSV debe tener encabezado con las columnas "codigo" y "fecha_hora" (o "fecha" y "hora").',
            ], 422);
        }

        $filas = [];
        $errores = [];
        $totalErrores = 0;
        $linea = 1;
        while (($c = fgetcsv($fh, 0, $separador)) !== false) {
            $linea++;
            if (count($c) === 1 && trim((string) $c[0]) === '') continue;

            $codigo = trim((string) ($c[$col['codigo']] ?? ''));
            $texto  = isset($col['fecha_hora'])
                ? trim((string) ($c[$col['fecha_hora']] ?? ''))
                : trim(($c[$col['fecha']] ?? '') . ' ' . ($c[$col['hora']] ?? ''));
            $momento = $this->leerFechaHora($texto);

            if ($codigo === '' || strlen($codigo) > 20 || !$momento) {
                $totalErrores++;
                if (count($errores) < 10) $errores[] = "Línea {$linea}: código o fecha inválidos ({$codigo} · {$texto}).";
                continue;
            }

            $tipo = isset($col['tipo']) ? trim((string) ($c[$col['tipo']] ?? '')) : '';
            $filas[] = [
                'codigo_biometrico' => $codigo,
                'fecha'             => $momento->toDateString(),
                'hora'              => $momento->format('H:i:s'),
                'tipo'              => self::TIPOS_PUNCH[$tipo] ?? ($tipo !== '' ? mb_substr($tipo, 0, 20) : null),
                'device_ip'         => isset($col['reloj']) ? (mb_substr(trim((string) ($c[$col['reloj']] ?? '')), 0, 45) ?: null) : null,
            ];
        }
        fclose($fh);

        if (!$filas) {
            return response()->json([
                'message' => 'El archivo no tiene marcas válidas.',
                'errores' => $errores,
            ], 422);
        }

        $empleadoPorCodigo = Empleado::whereIn('codigo_biometrico', array_unique(array_column($filas, 'codigo_biometrico')))
            ->pluck('id', 'codigo_biometrico');
        $ahora = now();
        $insertadas = 0;

        DB::transaction(function () use ($filas, $empleadoPorCodigo, $ahora, &$insertadas) {
            foreach (array_chunk($filas, 1000) as $lote) {
                $insertadas += Marcacion::insertOrIgnore(array_map(fn ($f) => $f + [
                    'id_empleado' => $empleadoPorCodigo->get($f['codigo_biometrico']),
                    'created_at'  => $ahora,
                    'updated_at'  => $ahora,
                ], $lote));
            }

            // Marcas cargadas antes de asignar el código en la ficha del empleado.
            DB::table('marcaciones')
                ->join('empleados', 'empleados.codigo_biometrico', '=', 'marcaciones.codigo_biometrico')
                ->whereNull('marcaciones.id_empleado')
                ->update(['marcaciones.id_empleado' => DB::raw('empleados.id')]);
        });

        $sinEmpleado = collect($filas)
            ->filter(fn ($f) => !$empleadoPorCodigo->has($f['codigo_biometrico']))
            ->countBy('codigo_biometrico')
            ->sortKeys(SORT_NATURAL)
            ->map(fn ($n, $codigo) => ['codigo' => (string) $codigo, 'marcas' => $n])
            ->values();

        $fechas = array_column($filas, 'fecha');
        $desde = min($fechas);
        $hasta = max($fechas);
        $nombre = $request->file('archivo')->getClientOriginalName();

        $this->logActividad(
            'generado',
            'Planillas',
            "Marcas cargadas desde {$nombre}: {$insertadas} nuevas de " . count($filas) . " leídas ({$desde} a {$hasta}).",
            null
        );

        return response()->json([
            'leidas'       => count($filas),
            'insertadas'   => $insertadas,
            'repetidas'    => count($filas) - $insertadas,
            'desde'        => $desde,
            'hasta'        => $hasta,
            'errores'      => $errores,
            'total_errores'=> $totalErrores,
            'sin_empleado' => $sinEmpleado,
        ], 201);
    }

    // Estado general de las marcas guardadas, para la pantalla "Cargar Marcas".
    public function estado()
    {
        return response()->json([
            'total'          => Marcacion::count(),
            'desde'          => Marcacion::min('fecha'),
            'hasta'          => Marcacion::max('fecha'),
            'sin_empleado'   => Marcacion::whereNull('id_empleado')->count(),
            'ultima_carga'   => Marcacion::max('created_at'),
            'con_codigo'     => Empleado::whereNotNull('codigo_biometrico')->where('codigo_biometrico', '<>', '')->count(),
            'relojes'        => Marcacion::whereNotNull('device_ip')->distinct()->orderBy('device_ip')->pluck('device_ip'),
        ]);
    }

    // Marcas cargadas en un período, para avisar en Nueva Planilla antes de generarla.
    public function resumenPeriodo(Request $request)
    {
        $data = $request->validate([
            'desde' => 'required|date',
            'hasta' => 'required|date|after_or_equal:desde',
        ]);

        $q = Marcacion::whereBetween('fecha', [$data['desde'], $data['hasta']]);

        return response()->json([
            'marcas'    => (clone $q)->count(),
            'empleados' => (clone $q)->whereNotNull('id_empleado')->distinct()->count('id_empleado'),
        ]);
    }

    // "Eliminar marcas" (solo administradores): primero se cuentan para
    // confirmar y luego se borran. Las planillas ya generadas no cambian,
    // porque guardan sus propios días trabajados.
    public function contarParaEliminar(Request $request)
    {
        $this->soloAdmin($request);

        return response()->json(['total' => $this->marcasDelRango($request)->count()]);
    }

    public function eliminar(Request $request)
    {
        $this->soloAdmin($request);

        $eliminadas = $this->marcasDelRango($request)->delete();
        $reloj = $request->reloj ? " del reloj {$request->reloj}" : '';

        $this->logActividad(
            'eliminado',
            'Planillas',
            "Marcas eliminadas{$reloj} del {$request->desde} al {$request->hasta}: {$eliminadas}.",
            null
        );

        return response()->json(['eliminadas' => $eliminadas]);
    }

    private function marcasDelRango(Request $request)
    {
        $request->validate([
            'desde' => 'required|date',
            'hasta' => 'required|date|after_or_equal:desde',
            'reloj' => 'nullable|string|max:45',
        ]);

        return Marcacion::whereBetween('fecha', [$request->desde, $request->hasta])
            ->when($request->reloj, fn ($q, $r) => $q->where('device_ip', $r));
    }

    private function normalizar(string $texto): string
    {
        $t = mb_strtolower(trim($texto, " \t\n\r\0\x0B\""));
        return str_replace([' ', 'á', 'é', 'í', 'ó', 'ú'], ['_', 'a', 'e', 'i', 'o', 'u'], $t);
    }

    // Acepta "2026-10-16 07:46:07" y los formatos que deja Excel al guardar ("16/10/2026 7:46").
    private function leerFechaHora(string $texto): ?Carbon
    {
        foreach (['Y-m-d H:i:s', 'Y-m-d H:i', 'd/m/Y H:i:s', 'd/m/Y H:i', 'Y/m/d H:i:s', 'Y/m/d H:i'] as $formato) {
            $f = \DateTime::createFromFormat('!' . $formato, $texto);
            $avisos = \DateTime::getLastErrors();
            $limpio = !$avisos || ($avisos['warning_count'] === 0 && $avisos['error_count'] === 0);
            if ($f && $limpio && (int) $f->format('Y') >= 2000 && (int) $f->format('Y') <= 2100) {
                return Carbon::instance($f);
            }
        }
        return null;
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
            'dias_trabajados' => Marcacion::diasTrabajados($data['id_empleado'], $data['desde'], $data['hasta']),
            'detalle'         => $dias,
        ]);
    }
}
