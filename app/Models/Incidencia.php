<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Incidencia extends Model
{
    use HasFactory, SoftDeletes;

    // Anular = borrado lógico. Las anuladas no salen en consultas normales
    // (listado, historial laboral); se ven con onlyTrashed().
    const DELETED_AT = 'anulada_at';

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

    public function usuario()
    {
        return $this->belongsTo(User::class, 'id_usuario');
    }

    public function usuarioAnula()
    {
        return $this->belongsTo(User::class, 'anulada_por')->withTrashed();
    }
}
