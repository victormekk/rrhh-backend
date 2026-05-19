<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class InformacionLaboral extends Model
{
    use HasFactory;

    protected $table = 'informacion_laboral';

    protected $fillable = [
        'tipo_contrato', 'fecha_inicio', 'fecha_cese', 'motivo_cese',
        'estado', 'moneda', 'forma_de_pago', 'num_cuenta',
        'salario_base', 'salario_quincenal', 'salario_diario', 'salario_por_hora',
        'usa_salario_minimo', 'id_banco', 'id_usuario',
    ];

    protected $casts = [
        'fecha_inicio'      => 'date',
        'fecha_cese'        => 'date',
        'usa_salario_minimo' => 'boolean',
        'salario_base'       => 'decimal:2',
        'salario_quincenal'  => 'decimal:2',
        'salario_diario'     => 'decimal:2',
        'salario_por_hora'   => 'decimal:2',
    ];

    public function empleado()
    {
        return $this->hasOne(Empleado::class, 'id_info_laboral');
    }

    public function banco()
    {
        return $this->belongsTo(Banco::class, 'id_banco');
    }
}
