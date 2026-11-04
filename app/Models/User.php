<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'id_rol',
        'activo',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        'activo' => 'boolean',
    ];

    public function rol(): BelongsTo
    {
        return $this->belongsTo(Rol::class, 'id_rol');
    }

    public function esAdmin(): bool
    {
        return (bool) $this->rol?->es_admin;
    }

    public function permisos(): array
    {
        return $this->rol?->permisosEfectivos() ?? [];
    }

    // true si tiene al menos uno de los permisos indicados.
    public function tienePermiso(string ...$permisos): bool
    {
        return $this->esAdmin() || array_intersect($permisos, $this->permisos()) !== [];
    }

    // Lo que necesita el frontend de la sesión: datos, rol y permisos.
    public function datosSesion(): array
    {
        $this->loadMissing('rol');

        return [
            ...$this->only(['id', 'name', 'email', 'activo', 'created_at']),
            'rol'      => $this->rol?->only(['id', 'nombre', 'es_admin']),
            'permisos' => $this->permisos(),
        ];
    }
}
