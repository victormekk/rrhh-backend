<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Empleado extends Model
{
    use HasFactory;

    protected $table = 'empleados';

    protected $fillable = [
        'nombres', 'apellidos', 'cedula', 'rtn', 'genero',
        'fecha_nacimiento', 'edad', 'estado_civil', 'num_hijos',
        'nacionalidad', 'residencia', 'telefono',
        'contacto_emergencia', 'telefono_emergencia', 'correo',
        'tipo_sangre', 'foto_path',
        'id_info_laboral', 'id_puesto', 'id_departamento', 'id_usuario',
    ];

    protected $appends = ['foto_url'];

    protected $casts = [
        'fecha_nacimiento' => 'date',
    ];

    public function getFotoUrlAttribute(): ?string
    {
        return $this->foto_path
            ? asset('storage/' . $this->foto_path)
            : null;
    }

    public function informacionLaboral()
    {
        return $this->belongsTo(InformacionLaboral::class, 'id_info_laboral');
    }

    public function puesto()
    {
        return $this->belongsTo(Puesto::class, 'id_puesto');
    }

    public function departamento()
    {
        return $this->belongsTo(Departamento::class, 'id_departamento');
    }

    public function incidencias()
    {
        return $this->hasMany(Incidencia::class, 'id_empleado');
    }

    public function otrosIngresos()
    {
        return $this->hasMany(OtroIngreso::class, 'id_empleado');
    }

    public function otasDeducciones()
    {
        return $this->hasMany(OtraDeduccion::class, 'id_empleado');
    }

    public function deduccionesCuotas()
    {
        return $this->hasMany(DeduccionCuota::class, 'id_empleado');
    }
}
