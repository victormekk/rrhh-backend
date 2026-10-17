<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MarcaJustificada extends Model
{
    protected $table = 'marcas_justificadas';

    protected $fillable = [
        'id_empleado', 'fecha', 'motivo', 'id_usuario',
    ];

    protected $casts = [
        'fecha' => 'date:Y-m-d',
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
