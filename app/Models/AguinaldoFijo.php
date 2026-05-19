<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AguinaldoFijo extends Model
{
    protected $table = 'aguinaldo_fijos';

    protected $fillable = [
        'nombre_aguinaldo', 'departamento', 'nombres', 'apellidos', 'cuenta',
        'fecha_inicio', 'salario_base', 'dias_trabajados', 'anticipo',
        'total_aguinaldo', 'fecha_generada', 'estado', 'tipo_aguinaldo',
        'id_empleado', 'id_info_laboral', 'id_departamento',
    ];

    protected $casts = [
        'fecha_inicio'    => 'date',
        'fecha_generada'  => 'date',
        'salario_base'    => 'decimal:2',
        'anticipo'        => 'decimal:2',
        'total_aguinaldo' => 'decimal:2',
    ];

    public function empleado()
    {
        return $this->belongsTo(Empleado::class, 'id_empleado');
    }
}
