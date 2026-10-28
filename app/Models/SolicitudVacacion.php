<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SolicitudVacacion extends Model
{
    use SoftDeletes;

    // Anular = borrado lógico. Las anuladas no salen en consultas normales
    // (listado, cálculo de saldo, PDF); se ven con onlyTrashed().
    const DELETED_AT = 'anulada_at';

    protected $table = 'solicitudes_vacaciones';

    protected $fillable = [
        'id_empleado', 'fecha_inicio', 'fecha_fin',
        'dias_tomados', 'observaciones', 'id_usuario',
    ];

    protected $casts = [
        'fecha_inicio' => 'date',
        'fecha_fin'    => 'date',
        'dias_tomados' => 'decimal:2',
    ];

    public function empleado()
    {
        return $this->belongsTo(Empleado::class, 'id_empleado');
    }

    public function usuarioAnula()
    {
        return $this->belongsTo(User::class, 'anulada_por')->withTrashed();
    }
}
