<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Puesto extends Model
{
    use HasFactory;

    protected $table = 'puestos';

    protected $fillable = ['nombre', 'estado', 'id_usuario'];

    public function empleados()
    {
        return $this->hasMany(Empleado::class, 'id_puesto');
    }
}
