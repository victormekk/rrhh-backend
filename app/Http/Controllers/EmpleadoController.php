<?php

namespace App\Http\Controllers;

use App\Models\CampoVariable;
use App\Models\Empleado;
use App\Models\InformacionLaboral;
use App\Traits\LogsActividad;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class EmpleadoController extends Controller
{
    use LogsActividad;
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
            // Orden alfabetico por departamento, y por apellidos dentro de cada uno.
            ->orderBy('departamentos.nombre')
            ->orderBy('empleados.apellidos');

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
        $request->validate([
            'nombres'             => 'required|string|max:30',
            'apellidos'           => 'required|string|max:30',
            'cedula'              => 'required|string|max:13|unique:empleados',
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
            'fecha_inicio'        => 'required|date',
            'forma_de_pago'       => 'required|string|max:50',
            'moneda'              => 'required|string|max:20',
            'salario_base'        => 'required|numeric|min:0',
            'usa_salario_minimo'  => 'boolean',
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
                'id_banco'           => $request->id_banco,
                'id_usuario'         => $request->user()->id,
            ]);

            $empleado = Empleado::create([
                ...$request->only([
                    'nombres', 'apellidos', 'cedula', 'rtn', 'genero',
                    'fecha_nacimiento', 'estado_civil', 'num_hijos',
                    'nacionalidad', 'residencia', 'telefono',
                    'contacto_emergencia', 'parentesco_emergencia', 'telefono_emergencia',
                    'correo', 'tipo_sangre', 'id_cargo', 'id_departamento',
                ]),
                'edad'            => now()->diffInYears($request->fecha_nacimiento),
                'id_info_laboral' => $infoLaboral->id,
                'id_usuario'      => $request->user()->id,
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

        $request->validate([
            'nombres'             => 'required|string|max:30',
            'apellidos'           => 'required|string|max:30',
            'cedula'              => "required|string|max:13|unique:empleados,cedula,{$id}",
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
            'fecha_inicio'        => 'required|date',
            'fecha_cese'          => 'nullable|date',
            'motivo_cese'         => 'nullable|string|max:300',
            'estado'              => 'required|string|max:20',
            'forma_de_pago'       => 'required|string|max:50',
            'moneda'              => 'required|string|max:20',
            'salario_base'        => 'required|numeric|min:0',
            'usa_salario_minimo'  => 'boolean',
            'num_cuenta'          => 'nullable|string|max:25',
            'id_banco'            => 'nullable|exists:bancos,id',
        ]);

        return DB::transaction(function () use ($request, $empleado) {
            $usaMinimo   = $request->boolean('usa_salario_minimo');
            $salarioBase = $usaMinimo
                ? (float) (CampoVariable::where('nombre_campo', 'salario_minimo')->value('monto') ?? 16317.60)
                : (float) $request->salario_base;

            $empleado->informacionLaboral->update([
                'tipo_contrato'      => $request->tipo_contrato,
                'fecha_inicio'       => $request->fecha_inicio,
                'fecha_cese'         => $request->fecha_cese,
                'motivo_cese'        => $request->motivo_cese,
                'estado'             => $request->estado,
                'moneda'             => $request->moneda,
                'forma_de_pago'      => $request->forma_de_pago,
                'num_cuenta'         => $request->num_cuenta,
                'salario_base'       => $salarioBase,
                'salario_quincenal'  => round($salarioBase / 2, 2),
                'salario_diario'     => round($salarioBase / 30, 2),
                'salario_por_hora'   => round($salarioBase / 30 / 8, 2),
                'usa_salario_minimo' => $usaMinimo,
                'id_banco'           => $request->id_banco,
            ]);

            $empleado->update([
                ...$request->only([
                    'nombres', 'apellidos', 'cedula', 'rtn', 'genero',
                    'fecha_nacimiento', 'estado_civil', 'num_hijos',
                    'nacionalidad', 'residencia', 'telefono',
                    'contacto_emergencia', 'parentesco_emergencia', 'telefono_emergencia',
                    'correo', 'tipo_sangre', 'id_cargo', 'id_departamento',
                ]),
                'edad' => now()->diffInYears($request->fecha_nacimiento),
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

        $sheet->mergeCells("A1:{$ultimaCol}1");
        $sheet->setCellValue('A1', 'INVERSIONES Y SERVICIOS S.A - HOTEL PALMA REAL');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        $sheet->mergeCells("A2:{$ultimaCol}2");
        $sheet->setCellValue('A2', 'INFORMACIÓN LABORAL');
        $sheet->getStyle('A2')->getFont()->setBold(true)->setSize(12);
        $sheet->getStyle('A2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        $sheet->mergeCells("A3:{$ultimaCol}3");
        $sheet->setCellValue('A3', sprintf('Generado: %s   |   Empleados: %d', now()->format('d/m/Y'), $empleados->count()));
        $sheet->getStyle('A3')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

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

    public function destroy($id)
    {
        $empleado = Empleado::with('informacionLaboral')->findOrFail($id);
        $empleado->informacionLaboral->update(['estado' => 'Inactivo']);

        $this->logActividad('eliminado', 'Empleados', "Empleado {$empleado->nombres} {$empleado->apellidos} desactivado.", $id);

        return response()->json(['message' => 'Empleado desactivado correctamente.']);
    }
}
