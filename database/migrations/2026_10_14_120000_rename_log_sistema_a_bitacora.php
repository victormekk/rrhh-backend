<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // El módulo "Log del Sistema" pasa a llamarse "Bitácora": se renombra la
    // tabla y el tipo de sus correlativos, conservando la numeración ya emitida.
    public function up(): void
    {
        Schema::rename('log_sistema', 'bitacora');

        DB::table('documentos_generados')->where('tipo', 'log_sistema')->update(['tipo' => 'bitacora']);
    }

    public function down(): void
    {
        DB::table('documentos_generados')->where('tipo', 'bitacora')->update(['tipo' => 'log_sistema']);

        Schema::rename('bitacora', 'log_sistema');
    }
};
