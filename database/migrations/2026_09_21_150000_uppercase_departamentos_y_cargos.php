<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('departamentos')->update(['nombre' => DB::raw('UPPER(nombre)')]);
        DB::table('cargos')->update(['nombre' => DB::raw('UPPER(nombre)')]);
    }

    public function down(): void
    {
        // No reversible: no se guarda el formato original de los nombres.
    }
};
