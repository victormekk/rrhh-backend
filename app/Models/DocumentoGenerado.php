<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DocumentoGenerado extends Model
{
    public $timestamps = false;

    protected $table = 'documentos_generados';

    protected $fillable = ['tipo', 'correlativo', 'referencia_id'];

    protected $casts = ['created_at' => 'datetime'];
}
