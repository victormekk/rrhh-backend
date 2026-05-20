<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $userId = DB::table('users')->value('id') ?? 1;

        $campos = [
            ['nombre_campo' => 'ihss',           'monto' => 297.58],
            ['nombre_campo' => 'salario_minimo',  'monto' => 16317.60],
        ];

        foreach ($campos as $campo) {
            $existe = DB::table('campos_variables')
                ->where('nombre_campo', $campo['nombre_campo'])
                ->exists();

            if (!$existe) {
                DB::table('campos_variables')->insert([
                    ...$campo,
                    'id_usuario' => $userId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        DB::table('campos_variables')->whereIn('nombre_campo', ['ihss', 'salario_minimo'])->delete();
    }
};
