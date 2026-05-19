<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DeduccionCuota extends Model
{
    use HasFactory;

    protected $table = 'deducciones_cuotas';

    protected $fillable = [
        'nombre_deduccion', 'monto', 'total_cuotas', 'cuotas_aplicadas',
        'estado', 'fecha', 'id_empleado', 'id_usuario',
    ];

    protected $casts = [
        'fecha' => 'date',
        'monto' => 'decimal:2',
    ];

    public function empleado()
    {
        return $this->belongsTo(Empleado::class, 'id_empleado');
    }
}
