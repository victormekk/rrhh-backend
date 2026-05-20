<?php

namespace App\Http\Controllers;

use App\Models\Empleado;
use App\Models\InformacionLaboral;
use App\Traits\LogsActividad;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class EmpleadoController extends Controller
{
    use LogsActividad;
    public function index(Request $request)
    {
        $query = Empleado::with(['informacionLaboral', 'puesto', 'departamento'])
            ->when($request->search, function ($q, $search) {
                $q->where(function ($q) use ($search) {
                    $q->where('nombres', 'like', "%{$search}%")
                      ->orWhere('apellidos', 'like', "%{$search}%")
                      ->orWhere('cedula', 'like', "%{$search}%");
                });
            })
            ->when($request->id_departamento, fn($q, $dep) => $q->where('id_departamento', $dep))
            ->when($request->estado, fn($q, $estado) =>
                $q->whereHas('informacionLaboral', fn($q) => $q->where('estado', $estado))
            )
            ->orderBy('apellidos');

        return response()->json($query->paginate(15));
    }

    public function show($id)
    {
        $empleado = Empleado::with(['informacionLaboral.banco', 'puesto', 'departamento'])
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
            'contacto_emergencia' => 'required|string|max:50',
            'telefono_emergencia' => 'required|string|max:30',
            'correo'              => 'nullable|email|max:50',
            'tipo_sangre'         => 'required|string|max:10',
            'id_puesto'           => 'required|exists:puestos,id',
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
            $infoLaboral = InformacionLaboral::create([
                'tipo_contrato'      => $request->tipo_contrato,
                'fecha_inicio'       => $request->fecha_inicio,
                'estado'             => 'Activo',
                'moneda'             => $request->moneda,
                'forma_de_pago'      => $request->forma_de_pago,
                'num_cuenta'         => $request->num_cuenta,
                'salario_base'       => $request->salario_base,
                'salario_quincenal'  => round($request->salario_base / 2, 2),
                'salario_diario'     => round($request->salario_base / 30, 2),
                'salario_por_hora'   => round($request->salario_base / 30 / 8, 2),
                'usa_salario_minimo' => $request->boolean('usa_salario_minimo'),
                'id_banco'           => $request->id_banco,
                'id_usuario'         => $request->user()->id,
            ]);

            $empleado = Empleado::create([
                ...$request->only([
                    'nombres', 'apellidos', 'cedula', 'rtn', 'genero',
                    'fecha_nacimiento', 'estado_civil', 'num_hijos',
                    'nacionalidad', 'residencia', 'telefono',
                    'contacto_emergencia', 'telefono_emergencia',
                    'correo', 'tipo_sangre', 'id_puesto', 'id_departamento',
                ]),
                'edad'            => now()->diffInYears($request->fecha_nacimiento),
                'id_info_laboral' => $infoLaboral->id,
                'id_usuario'      => $request->user()->id,
            ]);

            $this->logActividad('creado', 'Empleados', "Empleado {$empleado->nombres} {$empleado->apellidos} registrado.", $empleado->id);

            return response()->json(
                $empleado->load(['informacionLaboral.banco', 'puesto', 'departamento']),
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
            'contacto_emergencia' => 'required|string|max:50',
            'telefono_emergencia' => 'required|string|max:30',
            'correo'              => 'nullable|email|max:50',
            'tipo_sangre'         => 'required|string|max:10',
            'id_puesto'           => 'required|exists:puestos,id',
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
            $empleado->informacionLaboral->update([
                'tipo_contrato'      => $request->tipo_contrato,
                'fecha_inicio'       => $request->fecha_inicio,
                'fecha_cese'         => $request->fecha_cese,
                'motivo_cese'        => $request->motivo_cese,
                'estado'             => $request->estado,
                'moneda'             => $request->moneda,
                'forma_de_pago'      => $request->forma_de_pago,
                'num_cuenta'         => $request->num_cuenta,
                'salario_base'       => $request->salario_base,
                'salario_quincenal'  => round($request->salario_base / 2, 2),
                'salario_diario'     => round($request->salario_base / 30, 2),
                'salario_por_hora'   => round($request->salario_base / 30 / 8, 2),
                'usa_salario_minimo' => $request->boolean('usa_salario_minimo'),
                'id_banco'           => $request->id_banco,
            ]);

            $empleado->update([
                ...$request->only([
                    'nombres', 'apellidos', 'cedula', 'rtn', 'genero',
                    'fecha_nacimiento', 'estado_civil', 'num_hijos',
                    'nacionalidad', 'residencia', 'telefono',
                    'contacto_emergencia', 'telefono_emergencia',
                    'correo', 'tipo_sangre', 'id_puesto', 'id_departamento',
                ]),
                'edad' => now()->diffInYears($request->fecha_nacimiento),
            ]);

            $this->logActividad('editado', 'Empleados', "Empleado {$empleado->nombres} {$empleado->apellidos} actualizado.", $empleado->id);

            return response()->json(
                $empleado->fresh(['informacionLaboral.banco', 'puesto', 'departamento'])
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

    public function destroy($id)
    {
        $empleado = Empleado::with('informacionLaboral')->findOrFail($id);
        $empleado->informacionLaboral->update(['estado' => 'Inactivo']);

        $this->logActividad('eliminado', 'Empleados', "Empleado {$empleado->nombres} {$empleado->apellidos} desactivado.", $id);

        return response()->json(['message' => 'Empleado desactivado correctamente.']);
    }
}
