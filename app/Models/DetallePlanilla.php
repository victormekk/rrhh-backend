<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DetallePlanilla extends Model
{
    use HasFactory;

    protected $table = 'detalle_planillas';

    protected $fillable = [
        'id_cabecera_planilla', 'id_empleado', 'nombre_planilla', 'departamento',
        'tipo_planilla', 'dias_trabajados', 'salario_diario', 'salario_base',
        'desc_ingresos', 'otros_ingresos', 'ihss', 'retencion_ahorro',
        'crefisa', 'isr', 'transporte', 'radios', 'uniforme', 'garden',
        'desc_otras_deducciones', 'otras_deducciones', 'deduccion_neta',
        'salario_neto', 'cuenta_banco', 'fecha_generada', 'id_usuario',
    ];

    protected $casts = [
        'fecha_generada' => 'date',
    ];

    public function cabecera()
    {
        return $this->belongsTo(CabeceraPlanilla::class, 'id_cabecera_planilla');
    }

    public function empleado()
    {
        return $this->belongsTo(Empleado::class, 'id_empleado');
    }
}
