<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SolicitudVacacion extends Model
{
    protected $table = 'solicitudes_vacaciones';

    protected $fillable = [
        'id_empleado', 'fecha_inicio', 'fecha_fin',
        'dias_tomados', 'observaciones', 'id_usuario',
    ];

    protected $casts = [
        'fecha_inicio' => 'date',
        'fecha_fin'    => 'date',
        'dias_tomados' => 'decimal:2',
    ];

    public function empleado()
    {
        return $this->belongsTo(Empleado::class, 'id_empleado');
    }
}
