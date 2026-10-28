<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Las incidencias ya no se borran: se anulan y quedan en la sección "Anuladas".
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('incidencias', function (Blueprint $table) {
            $table->timestamp('anulada_at')->nullable()->after('id_usuario');
            $table->foreignId('anulada_por')->nullable()->after('anulada_at')->constrained('users');
            $table->string('motivo_anulacion', 300)->nullable()->after('anulada_por');
            $table->index('anulada_at');
        });
    }

    public function down(): void
    {
        Schema::table('incidencias', function (Blueprint $table) {
            $table->dropForeign(['anulada_por']);
            $table->dropIndex(['anulada_at']);
            $table->dropColumn(['anulada_at', 'anulada_por', 'motivo_anulacion']);
        });
    }
};
