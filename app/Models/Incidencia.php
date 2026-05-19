<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Incidencia extends Model
{
    use HasFactory;

    protected $table = 'incidencias';

    protected $fillable = [
        'fecha_incidencia', 'titulo', 'descripcion', 'grado',
        'id_empleado', 'id_usuario',
    ];

    protected $casts = [
        'fecha_incidencia' => 'date',
    ];

    public function empleado()
    {
        return $this->belongsTo(Empleado::class, 'id_empleado');
    }
}
