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
        'desc_ingresos', 'otros_ingresos', 'horas_extras', 'recargo_horas_extras', 'monto_horas_extras',
        'ihss', 'retencion_ahorro', 'crefisa', 'isr', 'transporte', 'radios',
        'uniforme', 'garden', 'i_vecinal',
        'desc_otras_deducciones', 'otras_deducciones', 'deduccion_neta',
        'salario_neto', 'cuenta_banco', 'fecha_generada', 'id_usuario',
    ];

    protected $casts = [
        'fecha_generada'       => 'date',
        'recargo_horas_extras' => 'integer',
    ];

    // Recargos permitidos para las horas extra (en %). 0 = sin recargo.
    public const RECARGOS_HORAS_EXTRAS = [0, 25, 50, 75];

    public static function montoHorasExtras(float $salarioDiario, float $horas, int $recargo): float
    {
        return round($salarioDiario / 8 * $horas * (1 + $recargo / 100), 2);
    }

    public function cabecera()
    {
        return $this->belongsTo(CabeceraPlanilla::class, 'id_cabecera_planilla');
    }

    public function empleado()
    {
        return $this->belongsTo(Empleado::class, 'id_empleado');
    }
}
