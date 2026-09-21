<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('empleados')->update([
            'nombres'                => DB::raw('UPPER(nombres)'),
            'apellidos'               => DB::raw('UPPER(apellidos)'),
            'nacionalidad'            => DB::raw('UPPER(nacionalidad)'),
            'residencia'              => DB::raw('UPPER(residencia)'),
            'contacto_emergencia'     => DB::raw('UPPER(contacto_emergencia)'),
            'parentesco_emergencia'   => DB::raw('UPPER(parentesco_emergencia)'),
        ]);

        DB::table('informacion_laboral')->update([
            'num_cuenta'  => DB::raw('UPPER(num_cuenta)'),
            'motivo_cese' => DB::raw('UPPER(motivo_cese)'),
        ]);
    }

    public function down(): void
    {
        // No reversible: no se guarda el formato original de los nombres.
    }
};
