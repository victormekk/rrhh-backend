<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Marcacion extends Model
{
    protected $table = 'marcaciones';

    protected $fillable = [
        'codigo_biometrico', 'id_empleado', 'fecha', 'hora', 'device_ip',
    ];

    protected $casts = [
        'fecha' => 'date',
    ];

    public function empleado()
    {
        return $this->belongsTo(Empleado::class, 'id_empleado');
    }
}
