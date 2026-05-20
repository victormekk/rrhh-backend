<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CampoVariable extends Model
{
    protected $table = 'campos_variables';

    protected $fillable = ['nombre_campo', 'monto', 'id_usuario'];

    protected $casts = ['monto' => 'decimal:2'];
}
