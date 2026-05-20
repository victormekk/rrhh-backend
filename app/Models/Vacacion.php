<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Vacacion extends Model
{
    protected $table = 'vacaciones';

    protected $fillable = [
        'primer_anio', 'segundo_anio', 'tercer_anio',
        'cuarto_anio_adelante', 'id_usuario',
    ];

    protected $casts = [
        'primer_anio'         => 'decimal:2',
        'segundo_anio'        => 'decimal:2',
        'tercer_anio'         => 'decimal:2',
        'cuarto_anio_adelante'=> 'decimal:2',
    ];
}
