<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IngresoDeduccion extends Model
{
    protected $table = 'ingresos_deducciones';

    protected $fillable = [
        'id_empleado', 'tipo', 'concepto', 'columna', 'monto', 'frecuencia',
        'quincena_mes', 'total_cuotas', 'fecha_inicio', 'estado', 'observacion', 'id_usuario',
    ];

    protected $casts = [
        'fecha_inicio' => 'date:Y-m-d',
        'monto'        => 'decimal:2',
        'quincena_mes' => 'integer',
        'total_cuotas' => 'integer',
    ];

    public const TIPOS       = ['Ingreso', 'Deduccion'];
    public const FRECUENCIAS = ['Quincenal', 'Mensual'];
    public const ESTADOS     = ['Activo', 'Pausado', 'Completado', 'Cancelado'];

    // Columnas de detalle_planillas en las que puede caer cada tipo. IHSS e ISR
    // quedan fuera: el IHSS sale de Campos Variables y el ISR se calcula a mano.
    public const COLUMNAS = [
        'Ingreso'   => ['otros_ingresos'],
        'Deduccion' => [
            'otras_deducciones', 'retencion_ahorro', 'crefisa', 'transporte',
            'radios', 'i_vecinal', 'uniforme', 'garden',
        ],
    ];

    // Columnas con descripción: ahí el concepto se anota como "Concepto: monto".
    public const DESCRIPCION_DE = [
        'otros_ingresos'    => 'desc_ingresos',
        'otras_deducciones' => 'desc_otras_deducciones',
    ];

    public function empleado()
    {
        return $this->belongsTo(Empleado::class, 'id_empleado');
    }

    public function aplicaciones()
    {
        return $this->hasMany(IngresoDeduccionAplicada::class, 'id_ingreso_deduccion');
    }

    // Pasa a Completado al llegar al total de cuotas pagadas, o vuelve a Activo
    // si se borró una planilla cerrada y ya no llega.
    public function recalcularEstado(): void
    {
        if ($this->total_cuotas === null || !in_array($this->estado, ['Activo', 'Completado'])) {
            return;
        }
        $pagadas = $this->aplicaciones()->where('aplicada', true)->count();
        $estado  = $pagadas >= $this->total_cuotas ? 'Completado' : 'Activo';
        if ($estado !== $this->estado) {
            $this->update(['estado' => $estado]);
        }
    }
}
