<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IngresoDeduccionAplicada extends Model
{
    protected $table = 'ingresos_deducciones_aplicadas';

    protected $fillable = [
        'id_ingreso_deduccion', 'id_cabecera_planilla', 'id_detalle_planilla', 'columna', 'monto', 'aplicada',
    ];

    protected $casts = [
        'monto'    => 'decimal:2',
        'aplicada' => 'boolean',
    ];

    public function ingresoDeduccion()
    {
        return $this->belongsTo(IngresoDeduccion::class, 'id_ingreso_deduccion');
    }

    public function planilla()
    {
        return $this->belongsTo(CabeceraPlanilla::class, 'id_cabecera_planilla');
    }

    public function detalle()
    {
        return $this->belongsTo(DetallePlanilla::class, 'id_detalle_planilla');
    }
}
