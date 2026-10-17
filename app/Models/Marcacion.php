<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Marcacion extends Model
{
    protected $table = 'marcaciones';

    protected $fillable = [
        'codigo_biometrico', 'id_empleado', 'fecha', 'hora', 'tipo', 'device_ip',
    ];

    protected $casts = [
        'fecha' => 'date',
    ];

    public function empleado()
    {
        return $this->belongsTo(Empleado::class, 'id_empleado');
    }

    // Días trabajados en el rango: días con 2 o más marcas (entrada y salida)
    // más los días con marca incompleta que RRHH justificó.
    public static function diasTrabajados(int $idEmpleado, string $desde, string $hasta): int
    {
        $completos = static::where('id_empleado', $idEmpleado)
            ->whereBetween('fecha', [$desde, $hasta])
            ->groupBy('fecha')
            ->havingRaw('COUNT(*) >= 2')
            ->toBase()
            ->pluck('fecha');

        $justificados = MarcaJustificada::where('id_empleado', $idEmpleado)
            ->whereBetween('fecha', [$desde, $hasta])
            ->toBase()
            ->pluck('fecha');

        return $completos->merge($justificados)
            ->map(fn ($f) => substr((string) $f, 0, 10))
            ->unique()
            ->count();
    }
}
