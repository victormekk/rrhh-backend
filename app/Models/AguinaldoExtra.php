<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AguinaldoExtra extends Model
{
    protected $table = 'aguinaldo_extras';

    protected $fillable = [
        'nombre_aguinaldo', 'departamento', 'nombres', 'apellidos', 'cuenta',
        'fecha_inicio', 'salario_base', 'diario', 'antiguedad', 'subtotal',
        'dias_promedio', 'promedio_dias', 'meses_promedio', 'sin_promedio',
        'anticipos', 'total_aguinaldo',
        'fecha_generada', 'fecha_corte', 'periodo_desde', 'periodo_hasta', 'estado', 'tipo_aguinaldo', 'concepto',
        'id_empleado', 'id_info_laboral', 'id_departamento',
    ];

    protected $casts = [
        'fecha_inicio'    => 'date',
        'fecha_generada'  => 'date',
        'fecha_corte'     => 'date',
        'periodo_desde'   => 'date',
        'periodo_hasta'   => 'date',
        'salario_base'    => 'decimal:2',
        'diario'          => 'decimal:2',
        'antiguedad'      => 'decimal:4',
        'promedio_dias'   => 'decimal:3',
        'sin_promedio'    => 'boolean',
        'subtotal'        => 'decimal:2',
        'anticipos'       => 'decimal:2',
        'total_aguinaldo' => 'decimal:2',
    ];

    public function empleado()
    {
        return $this->belongsTo(Empleado::class, 'id_empleado');
    }
}
