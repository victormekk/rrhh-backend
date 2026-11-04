<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Rol extends Model
{
    protected $table = 'roles';

    protected $fillable = ['nombre', 'descripcion', 'es_admin', 'permisos'];

    protected $casts = [
        'es_admin' => 'boolean',
        'permisos' => 'array',
    ];

    public function usuarios(): HasMany
    {
        return $this->hasMany(User::class, 'id_rol');
    }

    // Todos los permisos del catálogo (config/permisos.php), como "modulo.accion".
    public static function todosLosPermisos(): array
    {
        $todos = [];
        foreach (config('permisos') as $grupo) {
            foreach ($grupo['modulos'] as $modulo => $def) {
                foreach (array_keys($def['permisos']) as $accion) {
                    $todos[] = "{$modulo}.{$accion}";
                }
            }
        }

        return $todos;
    }

    // Permisos efectivos: el Administrador tiene todos; los demás, los marcados
    // que todavía existen en el catálogo.
    public function permisosEfectivos(): array
    {
        $todos = self::todosLosPermisos();

        return $this->es_admin
            ? $todos
            : array_values(array_intersect($todos, $this->permisos ?? []));
    }
}
