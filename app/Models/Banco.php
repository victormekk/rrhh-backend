<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Banco extends Model
{
    use HasFactory;

    protected $table = 'bancos';

    protected $fillable = ['nombre', 'estado', 'id_usuario'];

    public function informacionesLaborales()
    {
        return $this->hasMany(InformacionLaboral::class, 'id_banco');
    }
}
