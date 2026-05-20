<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('vacaciones')->update(['cuarto_anio_adelante' => 20]);
    }

    public function down(): void
    {
        DB::table('vacaciones')->update(['cuarto_anio_adelante' => 18]);
    }
};
