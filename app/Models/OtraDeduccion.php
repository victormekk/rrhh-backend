<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class OtraDeduccion extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'otras_deducciones';

    protected $fillable = [
        'descripcion', 'monto', 'nombre_planilla', 'fecha', 'id_empleado',
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
