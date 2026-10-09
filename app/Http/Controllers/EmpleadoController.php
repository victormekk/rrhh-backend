<?php

namespace App\Http\Controllers;

use App\Models\CampoVariable;
use App\Models\Empleado;
use App\Models\HistorialLaboral;
use App\Models\InformacionLaboral;
use App\Traits\EncabezadoExcel;
use App\Traits\LogsActividad;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use App\Rules\NoEs29Febrero;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class EmpleadoController extends Controller
{
    use LogsActividad, EncabezadoExcel;
    public function index(Request $request)
    {
        $query = Empleado::with(['informacionLaboral', 'cargo', 'departamento'])
            ->join('departamentos', 'departamentos.id', '=', 'empleados.id_departamento')
            ->select('empleados.*')
            ->when($request->search, function ($q, $search) {
                $q->where(function ($q) use ($search) {
                    $q->where('empleados.nombres', 'like', "%{$search}%")
                      ->orWhere('empleados.apellidos', 'like', "%{$search}%")
                      ->orWhere('empleados.cedula', 'like', "%{$search}%");
                });
            })
            ->when($request->id_departamento, fn($q, $dep) => $q->whereIn('empleados.id_departamento', (array) $dep))
            ->when($request->tipo_contrato, fn($q, $tipo) =>
                $q->whereHas('informacionLaboral', fn($q) => $q->where('tipo_contrato', $tipo))
            )
            ->when($request->estado, fn($q, $estado) =>
                $q->whereHas('informacionLaboral', fn($q) => $q->where('estado', $estado))
            )
            // Orden alfabetico por departamento (Administración, Animación, Bares...) y,
            // dentro de cada uno, por nombre; los que se llaman igual, por apellidos.
            ->orderBy('departamentos.nombre')
            ->orderBy('empleados.nombres')
            ->orderBy('empleados.apellidos')
            ->orderBy('empleados.id');

        return response()->json($query->paginate($request->input('per_page', 15)));
    }

    public function show($id)
    {
        $empleado = Empleado::with(['informacionLaboral.banco', 'cargo', 'departamento'])
            ->findOrFail($id);

        return response()->json($empleado);
    }

    public function store(Request $request)
    {
        if ($resp = $this->rechazarDniDuplicado($request->cedula)) {
            return $resp;
        }

        $request->validate([
            'nombres'             => 'required|string|max:30',
            'apellidos'           => 'required|string|max:30',
            'cedula'              => ['required', 'string', 'max:30', 'regex:/^[0-9-]+$/', 'unique:empleados'],
            'codigo_biometrico'   => 'nullable|string|max:20|unique:empleados',
            'rtn'                 => 'nullable|string|max:14',
            'genero'              => 'required|string|max:10',
            'fecha_nacimiento'    => 'required|date',
            'estado_civil'        => 'required|string|max:15',
            'num_hijos'           => 'integer|min:0',
            'nacionalidad'        => 'required|string|max:50',
            'residencia'          => 'required|string|max:60',
            'telefono'            => 'required|string|max:20',
            'contacto_emergencia'   => 'required|string|max:50',
            'parentesco_emergencia' => 'required|string|max:30',
            'telefono_emergencia'   => 'required|string|max:30',
            'correo'              => 'nullable|email|max:50',
            'tipo_sangre'         => 'required|string|max:10',
            'id_cargo'           => 'required|exists:cargos,id',
            'id_departamento'     => 'required|exists:departamentos,id',
            'tipo_contrato'       => 'required|string|max:20',
            'fecha_inicio'        => ['required', 'date', new NoEs29Febrero],
            'forma_de_pago'       => 'required|string|max:50',
            'moneda'              => 'required|string|max:20',
            'salario_base'        => 'required|numeric|min:0',
            'usa_salario_minimo'  => 'boolean',
            'sin_promedio_dias'   => 'boolean',
            'num_cuenta'          => 'nullable|string|max:25',
            'id_banco'            => 'nullable|exists:bancos,id',
        ]);

        return DB::transaction(function () use ($request) {
            $usaMinimo   = $request->boolean('usa_salario_minimo');
            $salarioBase = $usaMinimo
                ? (float) (CampoVariable::where('nombre_campo', 'salario_minimo')->value('monto') ?? 16317.60)
                : (float) $request->salario_base;

            $infoLaboral = InformacionLaboral::create([
                'tipo_contrato'      => $request->tipo_contrato,
                'fecha_inicio'       => $request->fecha_inicio,
                'estado'             => 'Activo',
                'moneda'             => $request->moneda,
                'forma_de_pago'      => $request->forma_de_pago,
                'num_cuenta'         => $request->num_cuenta,
                'salario_base'       => $salarioBase,
                'salario_quincenal'  => round($salarioBase / 2, 2),
                'salario_diario'     => round($salarioBase / 30, 2),
                'salario_por_hora'   => round($salarioBase / 30 / 8, 2),
                'usa_salario_minimo' => $usaMinimo,
                'sin_promedio_dias'  => $request->tipo_contrato === 'Extra' && $request->boolean('sin_promedio_dias'),
                'id_banco'           => $request->id_banco,
                'id_usuario'         => $request->user()->id,
            ]);

            $empleado = Empleado::create([
                ...$request->only([
                    'nombres', 'apellidos', 'cedula', 'codigo_biometrico', 'rtn', 'genero',
                    'fecha_nacimiento', 'estado_civil', 'num_hijos',
                    'nacionalidad', 'residencia', 'telefono',
                    'contacto_emergencia', 'parentesco_emergencia', 'telefono_emergencia',
                    'correo', 'tipo_sangre', 'id_cargo', 'id_departamento',
                ]),
                'edad'            => (int) Carbon::parse($request->fecha_nacimiento)->diffInYears(now()),
                'id_info_laboral' => $infoLaboral->id,
                'id_usuario'      => $request->user()->id,
            ]);

            HistorialLaboral::create([
                'id_empleado'         => $empleado->id,
                'tipo_evento'         => HistorialLaboral::INGRESO,
                'fecha'               => $request->fecha_inicio,
                'tipo_contrato_nuevo' => $request->tipo_contrato,
                'fecha_inicio_nueva'  => $request->fecha_inicio,
                'id_usuario'          => $request->user()->id,
            ]);

            $this->logActividad('creado', 'Empleados', "Empleado {$empleado->nombres} {$empleado->apellidos} registrado.", $empleado->id);

            return response()->json(
                $empleado->load(['informacionLaboral.banco', 'cargo', 'departamento']),
                201
            );
        });
    }

    public function update(Request $request, $id)
    {
        $empleado = Empleado::with('informacionLaboral')->findOrFail($id);
        $il = $empleado->informacionLaboral;

        if ($resp = $this->rechazarDniDuplicado($request->cedula, $empleado->id)) {
            return $resp;
        }

        // El paso a Inactivo y el regreso a Activo solo se hacen con "Dar de baja" y
        // "Reintegrar", para que siempre quede el motivo en el historial laboral.
        $estadosPermitidos = $il->estado === 'Inactivo' ? ['Inactivo'] : ['Activo', 'Suspendido'];

        $cambiaContrato = $request->tipo_contrato !== $il->tipo_contrato;
        $conNuevaFecha  = $cambiaContrato && $request->input('cambio_contrato.modo') === 'nueva_fecha';

        // Cambio de la fecha de inicio desde "Editar" (sin un cambio de contrato con nueva
        // fecha, que ya queda en el historial): exige motivo y queda en el historial laboral.
        $fechaActual = $il->fecha_inicio?->format('Y-m-d');
        $cambiaFecha = !$conNuevaFecha && $request->filled('fecha_inicio')
            && strtotime($request->fecha_inicio) !== false
            && date('Y-m-d', strtotime($request->fecha_inicio)) !== $fechaActual;

        $request->validate([
            'nombres'             => 'required|string|max:30',
            'apellidos'           => 'required|string|max:30',
            'cedula'              => ['required', 'string', 'max:30', 'regex:/^[0-9-]+$/', "unique:empleados,cedula,{$id}"],
            'codigo_biometrico'   => "nullable|string|max:20|unique:empleados,codigo_biometrico,{$id}",
            'rtn'                 => 'nullable|string|max:14',
            'genero'              => 'required|string|max:10',
            'fecha_nacimiento'    => 'required|date',
            'estado_civil'        => 'required|string|max:15',
            'num_hijos'           => 'integer|min:0',
            'nacionalidad'        => 'required|string|max:50',
            'residencia'          => 'required|string|max:60',
            'telefono'            => 'required|string|max:20',
            'contacto_emergencia'   => 'required|string|max:50',
            'parentesco_emergencia' => 'required|string|max:30',
            'telefono_emergencia'   => 'required|string|max:30',
            'correo'              => 'nullable|email|max:50',
            'tipo_sangre'         => 'required|string|max:10',
            'id_cargo'           => 'required|exists:cargos,id',
            'id_departamento'     => 'required|exists:departamentos,id',
            'tipo_contrato'       => ['required', Rule::in(['Fijo', 'Extra'])],
            'fecha_inicio'        => ['required', 'date', new NoEs29Febrero],
            'estado'              => ['required', Rule::in($estadosPermitidos)],
            // Cambio de tipo de contrato sin que el empleado se vaya: se respeta su fecha
            // de inicio, o se le liquida y se le da una nueva.
            'cambio_contrato'               => [Rule::requiredIf($cambiaContrato), 'nullable', 'array'],
            'cambio_contrato.modo'          => [Rule::requiredIf($cambiaContrato), 'nullable', Rule::in(['respetar', 'nueva_fecha'])],
            'cambio_contrato.fecha_inicio'  => [Rule::requiredIf($conNuevaFecha), 'nullable', 'date', new NoEs29Febrero],
            'cambio_contrato.liquidacion'   => [Rule::requiredIf($conNuevaFecha), 'nullable', Rule::in(HistorialLaboral::LIQUIDACION)],
            'cambio_contrato.observaciones' => 'nullable|string|max:500',
            'motivo_cambio_fecha'           => [Rule::requiredIf($cambiaFecha), 'nullable', 'string', 'min:5', 'max:500'],
            'forma_de_pago'       => 'required|string|max:50',
            'moneda'              => 'required|string|max:20',
            'salario_base'        => 'required|numeric|min:0',
            'usa_salario_minimo'  => 'boolean',
            'sin_promedio_dias'   => 'boolean',
            'num_cuenta'          => 'nullable|string|max:25',
            'id_banco'            => 'nullable|exists:bancos,id',
        ], [
            'estado.in' => $il->estado === 'Inactivo'
                ? 'Para reactivar al empleado usa "Reintegrar" en su ficha.'
                : 'Para pasar al empleado a inactivo usa "Dar de baja".',
            'cambio_contrato.required'      => 'Indica cómo se maneja el cambio de tipo de contrato.',
            'cambio_contrato.modo.required' => 'Indica cómo se maneja el cambio de tipo de contrato.',
            'motivo_cambio_fecha.required'  => 'Indica por qué se cambia la fecha de inicio.',
            'motivo_cambio_fecha.min'       => 'Describe un poco más por qué se cambia la fecha de inicio.',
        ]);

        $fechaInicio = $conNuevaFecha ? $request->input('cambio_contrato.fecha_inicio') : $request->fecha_inicio;

        return DB::transaction(function () use ($request, $empleado, $il, $cambiaContrato, $conNuevaFecha, $fechaInicio, $cambiaFecha, $fechaActual) {
            if ($cambiaFecha) {
                HistorialLaboral::create([
                    'id_empleado'           => $empleado->id,
                    'tipo_evento'           => HistorialLaboral::CAMBIO_FECHA,
                    'fecha'                 => now()->toDateString(),
                    'fecha_inicio_anterior' => $fechaActual,
                    'fecha_inicio_nueva'    => $fechaInicio,
                    'observaciones'         => $request->motivo_cambio_fecha,
                    'id_usuario'            => $request->user()->id,
                ]);
            }

            if ($cambiaContrato) {
                $liquidacion = $conNuevaFecha ? $request->input('cambio_contrato.liquidacion') : null;
                HistorialLaboral::create([
                    'id_empleado'            => $empleado->id,
                    'tipo_evento'            => HistorialLaboral::CAMBIO_CONTRATO,
                    'fecha'                  => $conNuevaFecha ? $fechaInicio : now()->toDateString(),
                    'tipo_contrato_anterior' => $il->tipo_contrato,
                    'tipo_contrato_nuevo'    => $request->tipo_contrato,
                    'fecha_inicio_anterior'  => $il->fecha_inicio,
                    'fecha_inicio_nueva'     => $conNuevaFecha ? $fechaInicio : null,
                    'liquidacion'            => $liquidacion,
                    'fecha_liquidacion'      => $liquidacion === 'Sí' ? now()->toDateString() : null,
                    'observaciones'          => $request->input('cambio_contrato.observaciones')
                        ?: ($conNuevaFecha ? 'Se liquida y se asigna nueva fecha de inicio.' : 'Se respeta la fecha de inicio.'),
                    'id_usuario'             => $request->user()->id,
                ]);
            }

            $usaMinimo   = $request->boolean('usa_salario_minimo');
            $salarioBase = $usaMinimo
                ? (float) (CampoVariable::where('nombre_campo', 'salario_minimo')->value('monto') ?? 16317.60)
                : (float) $request->salario_base;

            $il->update([
                'tipo_contrato'      => $request->tipo_contrato,
                'fecha_inicio'       => $fechaInicio,
                'estado'             => $request->estado,
                'moneda'             => $request->moneda,
                'forma_de_pago'      => $request->forma_de_pago,
                'num_cuenta'         => $request->num_cuenta,
                'salario_base'       => $salarioBase,
                'salario_quincenal'  => round($salarioBase / 2, 2),
                'salario_diario'     => round($salarioBase / 30, 2),
                'salario_por_hora'   => round($salarioBase / 30 / 8, 2),
                'usa_salario_minimo' => $usaMinimo,
                'sin_promedio_dias'  => $request->tipo_contrato === 'Extra' && $request->boolean('sin_promedio_dias'),
                'id_banco'           => $request->id_banco,
            ]);

            $empleado->update([
                ...$request->only([
                    'nombres', 'apellidos', 'cedula', 'codigo_biometrico', 'rtn', 'genero',
                    'fecha_nacimiento', 'estado_civil', 'num_hijos',
                    'nacionalidad', 'residencia', 'telefono',
                    'contacto_emergencia', 'parentesco_emergencia', 'telefono_emergencia',
                    'correo', 'tipo_sangre', 'id_cargo', 'id_departamento',
                ]),
                'edad' => (int) Carbon::parse($request->fecha_nacimiento)->diffInYears(now()),
            ]);

            $this->logActividad('editado', 'Empleados', "Empleado {$empleado->nombres} {$empleado->apellidos} actualizado.", $empleado->id);

            return response()->json(
                $empleado->fresh(['informacionLaboral.banco', 'cargo', 'departamento'])
            );
        });
    }

    public function uploadFoto(Request $request, $id)
    {
        $request->validate(['foto' => 'required|image|max:2048']);

        $empleado = Empleado::findOrFail($id);

        if ($empleado->foto_path) {
            Storage::disk('public')->delete($empleado->foto_path);
        }

        $path = $request->file('foto')->store('empleados/fotos', 'public');
        $empleado->update(['foto_path' => $path]);

        return response()->json([
            'foto_path' => $path,
            'foto_url'  => asset('storage/' . $path),
        ]);
    }

    public function deleteFoto($id)
    {
        $empleado = Empleado::findOrFail($id);

        if ($empleado->foto_path) {
            Storage::disk('public')->delete($empleado->foto_path);
            $empleado->update(['foto_path' => null]);
        }

        return response()->json(['message' => 'Foto eliminada.']);
    }

    // Exporta a Excel la información laboral básica (nombre, DNI, fecha de
    // inicio, salario mensual, departamento y cargo) de uno o varios
    // empleados seleccionados. Usado por "Información Laboral".
    public function exportarInformacionLaboral(Request $request)
    {
        $ids = (array) $request->input('ids', []);
        abort_if(empty($ids), 422, 'Selecciona al menos un empleado.');

        $empleados = Empleado::with(['informacionLaboral', 'cargo', 'departamento'])
            ->whereIn('id', $ids)
            ->orderBy('apellidos')
            ->orderBy('nombres')
            ->get();

        $columnas = [
            'A' => 'Nombre completo', 'B' => 'DNI', 'C' => 'Fecha de inicio',
            'D' => 'Departamento', 'E' => 'Cargo', 'F' => 'Salario mensual',
        ];
        $ultimaCol = 'F';

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Información Laboral');

        // Logo a la izquierda y los títulos a su derecha (ver App\Traits\EncabezadoExcel)
        $this->encabezadoExcel($sheet, $ultimaCol, [
            ['INVERSIONES Y SERVICIOS S.A - HOTEL PALMA REAL', 14],
            ['INFORMACIÓN LABORAL', 12],
            [sprintf('Generado: %s   |   Empleados: %d', now()->format('d/m/Y'), $empleados->count()), null],
        ]);

        $row = 5;
        foreach ($columnas as $col => $titulo) {
            $sheet->setCellValue("{$col}{$row}", $titulo);
        }
        $sheet->getStyle("A{$row}:{$ultimaCol}{$row}")->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $sheet->getStyle("A{$row}:{$ultimaCol}{$row}")->getFill()
            ->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('3B2B16');
        $row++;

        foreach ($empleados as $emp) {
            $il = $emp->informacionLaboral;
            $sheet->setCellValue("A{$row}", trim("{$emp->nombres} {$emp->apellidos}"));
            $sheet->setCellValue("B{$row}", $emp->cedula ?? '—');
            $sheet->setCellValue("C{$row}", $il?->fecha_inicio
                ? \Carbon\Carbon::parse($il->fecha_inicio)->format('d/m/Y')
                : '—');
            $sheet->setCellValue("D{$row}", $emp->departamento?->nombre ?? '—');
            $sheet->setCellValue("E{$row}", $emp->cargo?->nombre ?? '—');
            $sheet->setCellValueExplicit("F{$row}", round((float) ($il->salario_base ?? 0), 2), DataType::TYPE_NUMERIC);
            $row++;
        }

        $sheet->getStyle("F6:F" . ($row - 1))->getNumberFormat()->setFormatCode('#,##0.00');
        foreach (array_keys($columnas) as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $tempFile = tempnam(sys_get_temp_dir(), 'infolaboral') . '.xlsx';
        $this->anchoColumnaLogo($sheet);
        (new Xlsx($spreadsheet))->save($tempFile);

        $this->logActividad(
            'generado',
            'Empleados',
            "Exportó información laboral de {$empleados->count()} empleado(s) a Excel.",
            null
        );

        return response()->download($tempFile, 'InformacionLaboral_' . now()->format('dmY') . '.xlsx')
            ->deleteFileAfterSend(true);
    }

    // Nunca se borra: dar de baja exige fecha, motivo y liquidación (ver HistorialLaboralController).
    public function destroy(Request $request, $id)
    {
        return app(HistorialLaboralController::class)->cese($request, $id);
    }

    // ─── DNI duplicado ─────────────────────────────────────────────
    // Para el formulario: avisa al escribir el DNI si ya pertenece a otro empleado.
    public function verificarDni(Request $request)
    {
        $existente = $request->cedula ? $this->buscarPorCedula($request->cedula, $request->excluir) : null;

        return response()->json([
            'existe'   => (bool) $existente,
            'empleado' => $existente ? $this->resumenEmpleado($existente) : null,
        ]);
    }

    private function buscarPorCedula(string $cedula, $excluirId = null): ?Empleado
    {
        return Empleado::with('informacionLaboral:id,estado,fecha_cese')
            ->conCedula($cedula, $excluirId)
            ->first(['id', 'nombres', 'apellidos', 'cedula', 'id_info_laboral']);
    }

    private function rechazarDniDuplicado(?string $cedula, $excluirId = null)
    {
        $existente = $cedula ? $this->buscarPorCedula($cedula, $excluirId) : null;
        if (!$existente) return null;

        $nombre = trim("{$existente->nombres} {$existente->apellidos}");
        $msg = $existente->informacionLaboral?->estado === 'Inactivo'
            ? "El DNI ya pertenece a {$nombre}, que está inactivo. Reintégralo desde su ficha en lugar de registrarlo de nuevo."
            : "El DNI ya pertenece a {$nombre}.";

        return response()->json([
            'message'            => $msg,
            'errors'             => ['cedula' => [$msg]],
            'empleado_existente' => $this->resumenEmpleado($existente),
        ], 422);
    }

    private function resumenEmpleado(Empleado $e): array
    {
        return [
            'id'         => $e->id,
            'nombre'     => trim("{$e->nombres} {$e->apellidos}"),
            'cedula'     => $e->cedula,
            'estado'     => $e->informacionLaboral?->estado,
            'fecha_cese' => $e->informacionLaboral?->fecha_cese?->format('Y-m-d'),
        ];
    }

    // ─── Cuentas bancarias (módulo Bancos) ────────────────────────
    // Empleados activos sin número de cuenta: hoy cobran por cheque.
    public function sinCuenta()
    {
        $empleados = Empleado::with(['informacionLaboral:id,tipo_contrato,fecha_inicio,num_cuenta,estado', 'cargo:id,nombre', 'departamento:id,nombre'])
            ->whereHas('informacionLaboral', fn ($q) => $q->where('estado', 'Activo')
                ->where(fn ($w) => $w->whereNull('num_cuenta')->orWhere('num_cuenta', '')))
            ->orderBy('nombres')->orderBy('apellidos')
            ->get(['id', 'nombres', 'apellidos', 'cedula', 'id_info_laboral', 'id_cargo', 'id_departamento']);

        return response()->json($empleados->map(fn ($e) => [
            'id'            => $e->id,
            'nombre'        => trim("{$e->nombres} {$e->apellidos}"),
            'cedula'        => $e->cedula,
            'departamento'  => $e->departamento?->nombre,
            'cargo'         => $e->cargo?->nombre,
            'tipo_contrato' => $e->informacionLaboral->tipo_contrato,
            'fecha_inicio'  => $e->informacionLaboral->fecha_inicio?->toDateString(),
        ]));
    }

    // Asigna banco y número de cuenta: el empleado pasa a cobrar por transferencia.
    // También se aplica a las planillas de pago y especiales que siguen abiertas;
    // las cerradas no se modifican en nada.
    public function asignarCuenta(Request $request, $id)
    {
        $empleado = Empleado::with('informacionLaboral')->findOrFail($id);

        $request->merge(['num_cuenta' => strtoupper(trim((string) $request->num_cuenta))]);
        $data = $request->validate([
            'id_banco'   => 'required|exists:bancos,id',
            'num_cuenta' => 'required|string|max:25',
        ]);

        $duplicada = Empleado::whereHas('informacionLaboral', fn ($q) => $q->where('num_cuenta', $data['num_cuenta']))
            ->where('id', '!=', $empleado->id)->first();
        abort_if($duplicada, 422, "Esa cuenta ya está registrada a nombre de {$duplicada?->nombres} {$duplicada?->apellidos}.");

        return DB::transaction(function () use ($empleado, $data) {
            $empleado->informacionLaboral->update([
                'num_cuenta'    => $data['num_cuenta'],
                'id_banco'      => $data['id_banco'],
                'forma_de_pago' => 'Transferencia',
            ]);

            $planillas = DB::table('detalle_planillas')
                ->join('cabecera_planillas as c', 'c.id', '=', 'detalle_planillas.id_cabecera_planilla')
                ->where('detalle_planillas.id_empleado', $empleado->id)
                ->where('c.estado', '!=', 'Cerrado')
                ->update(['detalle_planillas.cuenta_banco' => $data['num_cuenta']]);

            $especiales = 0;
            foreach ([\App\Models\AguinaldoFijo::class, \App\Models\AguinaldoExtra::class] as $modelo) {
                $especiales += $modelo::where('id_empleado', $empleado->id)
                    ->where('estado', '!=', 'Cerrado')
                    ->update(['cuenta' => $data['num_cuenta']]);
            }

            $banco = DB::table('bancos')->where('id', $data['id_banco'])->value('nombre');
            $this->logActividad('editado', 'Empleados',
                "Cuenta {$data['num_cuenta']} ({$banco}) asignada a {$empleado->nombres} {$empleado->apellidos}."
                . ($planillas + $especiales ? " Aplicada a {$planillas} planilla(s) de pago y {$especiales} especial(es) abiertas." : ''),
                $empleado->id);

            return response()->json([
                'message'                => 'Cuenta asignada.',
                'planillas_actualizadas' => $planillas,
                'especiales_actualizadas' => $especiales,
            ]);
        });
    }
}
