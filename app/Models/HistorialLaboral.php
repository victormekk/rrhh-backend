<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HistorialLaboral extends Model
{
    protected $table = 'historial_laboral';

    public const INGRESO          = 'Ingreso';
    public const CESE             = 'Cese';
    public const REINTEGRO        = 'Reintegro';
    public const CAMBIO_CONTRATO  = 'Cambio de contrato';
    public const CAMBIO_FECHA     = 'Cambio de fecha'; // corrección de la fecha de inicio desde "Editar"
    public const CAMBIO_PUESTO    = 'Cambio de puesto'; // cambio de cargo y/o departamento

    public const TIPOS_EVENTO = [self::INGRESO, self::CESE, self::REINTEGRO, self::CAMBIO_CONTRATO, self::CAMBIO_FECHA, self::CAMBIO_PUESTO];

    public const MOTIVOS_CESE = [
        'Despido', 'Despido justificado', 'Renuncia', 'Jubilación',
        'Abandono de trabajo', 'Fin de contrato', 'Mutuo acuerdo', 'Fallecimiento',
    ];

    public const LIQUIDACION = ['Sí', 'No', 'Pendiente'];

    protected $fillable = [
        'id_empleado', 'tipo_evento', 'fecha',
        'tipo_contrato_anterior', 'tipo_contrato_nuevo',
        'fecha_inicio_anterior', 'fecha_inicio_nueva',
        'cargo_anterior', 'cargo_nuevo', 'departamento_anterior', 'departamento_nuevo',
        'motivo_cese', 'liquidacion', 'fecha_liquidacion',
        'observaciones', 'id_usuario',
    ];

    protected $casts = [
        'fecha'                 => 'date:Y-m-d',
        'fecha_inicio_anterior' => 'date:Y-m-d',
        'fecha_inicio_nueva'    => 'date:Y-m-d',
        'fecha_liquidacion'     => 'date:Y-m-d',
    ];

    public function empleado()
    {
        return $this->belongsTo(Empleado::class, 'id_empleado');
    }

    public function usuario()
    {
        return $this->belongsTo(User::class, 'id_usuario');
    }
}
