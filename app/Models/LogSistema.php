<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LogSistema extends Model
{
    protected $table = 'log_sistema';

    protected $fillable = [
        'id_usuario', 'accion', 'descripcion',
        'id_objeto_involucrado', 'objeto_actualizado', 'fecha',
    ];

    protected $casts = [
        'fecha' => 'date',
    ];

    public function usuario()
    {
        return $this->belongsTo(User::class, 'id_usuario');
    }
}
