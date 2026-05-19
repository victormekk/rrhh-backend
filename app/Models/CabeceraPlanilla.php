<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CabeceraPlanilla extends Model
{
    use HasFactory;

    protected $table = 'cabecera_planillas';

    protected $fillable = [
        'nombre_planilla', 'tipo_planilla', 'estado', 'fecha_generada', 'id_usuario',
    ];

    protected $casts = [
        'fecha_generada' => 'date',
    ];

    public function detalles()
    {
        return $this->hasMany(DetallePlanilla::class, 'id_cabecera_planilla');
    }
}
