<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AguinaldoExtra extends Model
{
    protected $table = 'aguinaldo_extras';

    protected $fillable = [
        'nombre_aguinaldo', 'departamento', 'nombres', 'apellidos', 'cuenta',
        'fecha_inicio', 'salario_base', 'diario', 'antiguedad', 'subtotal',
        'dias_promedio', 'anticipos', 'total_aguinaldo',
        'fecha_generada', 'estado', 'tipo_aguinaldo',
        'id_empleado', 'id_info_laboral', 'id_departamento',
    ];

    protected $casts = [
        'fecha_inicio'    => 'date',
        'fecha_generada'  => 'date',
        'salario_base'    => 'decimal:2',
        'diario'          => 'decimal:2',
        'antiguedad'      => 'decimal:2',
        'subtotal'        => 'decimal:2',
        'anticipos'       => 'decimal:2',
        'total_aguinaldo' => 'decimal:2',
    ];

    public function empleado()
    {
        return $this->belongsTo(Empleado::class, 'id_empleado');
    }
}
