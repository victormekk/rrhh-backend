<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Roles con permisos por checks (Configuración → Gestión de Roles).
// Reemplaza la columna users.rol ('admin' / 'rrhh') por users.id_rol.
return new class extends Migration
{
    // Lo mismo que podía hacer el rol "rrhh" antes de este cambio:
    // todo menos Configuración y las acciones que eran solo de administrador.
    private const PERMISOS_RRHH = [
        'salarios.ver',
        'dashboard.ver_montos',
        'empleados.ver', 'empleados.ver_historial', 'empleados.crear', 'empleados.editar', 'empleados.baja',
        'departamentos.ver', 'departamentos.crear', 'departamentos.editar',
        'bancos.ver', 'bancos.crear', 'bancos.editar', 'bancos.eliminar', 'bancos.asignar_cuentas',
        'incidencias.ver', 'incidencias.crear', 'incidencias.editar',
        'constancias.laboral', 'constancias.bancaria', 'constancias.voucher',
        'vacaciones.ver', 'vacaciones.crear', 'vacaciones.editar',
        'cumpleanos.ver',
        'planillas.ver', 'planillas.crear', 'planillas.editar', 'planillas.cerrar', 'planillas.exportar',
        'aguinaldo.ver', 'aguinaldo.crear', 'aguinaldo.editar', 'aguinaldo.cerrar', 'aguinaldo.exportar',
        'ingresos_deducciones.ver', 'ingresos_deducciones.crear', 'ingresos_deducciones.editar',
        'marcas.ver', 'marcas.cargar', 'marcas.justificar', 'marcas.exportar',
        'estadistica.ver', 'estadistica.exportar',
        'informacion_laboral.ver', 'informacion_laboral.exportar',
        'movimientos.ver', 'movimientos.editar', 'movimientos.exportar',
    ];

    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('nombre', 50)->unique();
            $table->string('descripcion', 255)->nullable();
            // El rol Administrador tiene todos los permisos y no se puede editar ni eliminar.
            $table->boolean('es_admin')->default(false);
            $table->json('permisos');
            $table->timestamps();
        });

        $ahora = now();
        $idAdmin = DB::table('roles')->insertGetId([
            'nombre'      => 'Administrador',
            'descripcion' => 'Acceso total al sistema.',
            'es_admin'    => true,
            'permisos'    => json_encode([]),
            'created_at'  => $ahora,
            'updated_at'  => $ahora,
        ]);
        $idRrhh = DB::table('roles')->insertGetId([
            'nombre'      => 'RRHH',
            'descripcion' => 'Acceso operativo: empleados, planillas, vacaciones, incidencias.',
            'es_admin'    => false,
            'permisos'    => json_encode(self::PERMISOS_RRHH),
            'created_at'  => $ahora,
            'updated_at'  => $ahora,
        ]);

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('id_rol')->nullable()->after('email')->constrained('roles');
        });

        DB::table('users')->where('rol', 'admin')->update(['id_rol' => $idAdmin]);
        DB::table('users')->whereNull('id_rol')->update(['id_rol' => $idRrhh]);

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('rol');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('rol', 20)->default('rrhh')->after('email');
        });

        $idsAdmin = DB::table('roles')->where('es_admin', true)->pluck('id');
        DB::table('users')->whereIn('id_rol', $idsAdmin)->update(['rol' => 'admin']);

        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('id_rol');
        });

        Schema::dropIfExists('roles');
    }
};
