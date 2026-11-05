<?php

use App\Http\Controllers\AguinaldoController;
use App\Http\Controllers\AsistenciaController;
use App\Http\Controllers\MarcasIncompletasController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BancoController;
use App\Http\Controllers\CamposVariablesController;
use App\Http\Controllers\ConstanciaController;
use App\Http\Controllers\CumpleanosController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EstadisticaLaboralController;
use App\Http\Controllers\DepartamentoController;
use App\Http\Controllers\EmpleadoController;
use App\Http\Controllers\HistorialLaboralController;
use App\Http\Controllers\IncidenciaController;
use App\Http\Controllers\IngresoDeduccionController;
use App\Http\Controllers\LogSistemaController;
use App\Http\Controllers\PlanillaController;
use App\Http\Controllers\CargoController;
use App\Http\Controllers\RolController;
use App\Http\Controllers\SalariosDevengadosController;
use App\Http\Controllers\ListadoCumpleanosController;
use App\Http\Controllers\UsuarioController;
use App\Http\Controllers\VacacionController;
use Illuminate\Support\Facades\Route;

Route::middleware('throttle:login')->post('/login', [AuthController::class, 'login']);

// Health check de Railway (railway.json): confirma que la app arrancó y llega a la base de datos.
Route::get('/salud', function () {
    \Illuminate\Support\Facades\DB::select('select 1');

    return response()->json(['estado' => 'ok']);
});

// Recibe las marcaciones del agente local de reloj biometrico (zkteco-agente/).
// Corre desatendido en una PC del hotel, sin sesion de usuario, por eso va
// fuera del grupo auth:sanctum y se protege con un token compartido simple
// (ver App\Http\Middleware\VerificaTokenAsistencia y el .env ASISTENCIA_TOKEN).
Route::middleware('asistencia.token')->post('/asistencias/importar', [AsistenciaController::class, 'importar']);

// Cada ruta exige un permiso del rol del usuario (config/permisos.php, Gestión de Roles).
// 'permiso:a,b' deja pasar con cualquiera de los permisos indicados.
Route::middleware(['auth:sanctum', 'usuario.activo'])->group(function () {
    // La lista de empleados también la usan, para elegir empleado, estos módulos.
    $listaEmpleados = 'permiso:empleados.ver,vacaciones.ver,incidencias.ver,constancias.laboral,constancias.bancaria,'
        . 'constancias.voucher,ingresos_deducciones.ver,estadistica.ver,informacion_laboral.ver,salarios_devengados.ver';

    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);

    // Dashboard: los conteos los ve cualquiera; el gráfico de montos, con permiso.
    Route::get('/dashboard/stats', [DashboardController::class, 'stats']);
    Route::get('/dashboard/planillas-chart', [DashboardController::class, 'chartPlanillas'])->middleware('permiso:dashboard.ver_montos');
    Route::get('/dashboard/jubilaciones', [DashboardController::class, 'jubilaciones'])->middleware('permiso:dashboard.ver_jubilaciones');

    // Marcas de los relojes (Cargar Marcas)
    Route::get('/asistencias/resumen', [AsistenciaController::class, 'resumen'])->middleware('permiso:marcas.ver,planillas.ver');
    Route::get('/asistencias/estado', [AsistenciaController::class, 'estado'])->middleware('permiso:marcas.ver');
    Route::get('/asistencias/periodo', [AsistenciaController::class, 'resumenPeriodo'])->middleware('permiso:marcas.ver,planillas.crear');
    Route::post('/asistencias/cargar-csv', [AsistenciaController::class, 'cargarCsv'])->middleware('permiso:marcas.cargar');
    Route::get('/asistencias/eliminar/contar', [AsistenciaController::class, 'contarParaEliminar'])->middleware('permiso:marcas.eliminar');
    Route::delete('/asistencias', [AsistenciaController::class, 'eliminar'])->middleware('permiso:marcas.eliminar');

    // Marcas incompletas (un solo marcaje en el día) y sus justificaciones
    Route::get('/marcas-incompletas', [MarcasIncompletasController::class, 'index'])->middleware('permiso:marcas.ver');
    Route::get('/marcas-incompletas/excel', [MarcasIncompletasController::class, 'excel'])->middleware('permiso:marcas.exportar');
    Route::get('/marcas-incompletas/pdf', [MarcasIncompletasController::class, 'pdf'])->middleware('permiso:marcas.exportar');
    Route::post('/marcas-incompletas/justificar', [MarcasIncompletasController::class, 'justificar'])->middleware('permiso:marcas.justificar');
    Route::delete('/marcas-incompletas/justificar/{justificada}', [MarcasIncompletasController::class, 'quitarJustificacion'])->middleware('permiso:marcas.justificar');

    // Cumpleaños
    Route::get('/cumpleanos', [CumpleanosController::class, 'index'])->middleware('permiso:cumpleanos.ver');
    Route::get('/cumpleanos/pdf', [CumpleanosController::class, 'pdf'])->middleware('permiso:cumpleanos.exportar');

    // Empleados
    Route::post('/empleados/{empleado}/foto', [EmpleadoController::class, 'uploadFoto'])->middleware('permiso:empleados.crear,empleados.editar');
    Route::delete('/empleados/{empleado}/foto', [EmpleadoController::class, 'deleteFoto'])->middleware('permiso:empleados.editar');
    Route::get('/empleados-informacion-laboral/excel', [EmpleadoController::class, 'exportarInformacionLaboral'])->middleware('permiso:informacion_laboral.exportar');
    Route::get('/empleados-sin-cuenta', [EmpleadoController::class, 'sinCuenta'])->middleware('permiso:bancos.ver');
    Route::post('/empleados/{empleado}/cuenta', [EmpleadoController::class, 'asignarCuenta'])->middleware('permiso:bancos.asignar_cuentas');
    Route::get('/empleados-verificar-dni', [EmpleadoController::class, 'verificarDni'])->middleware('permiso:empleados.crear,empleados.editar');
    Route::get('/empleados', [EmpleadoController::class, 'index'])->middleware($listaEmpleados);
    Route::post('/empleados', [EmpleadoController::class, 'store'])->middleware('permiso:empleados.crear');
    Route::get('/empleados/{empleado}', [EmpleadoController::class, 'show'])->middleware('permiso:empleados.ver');
    Route::match(['put', 'patch'], '/empleados/{empleado}', [EmpleadoController::class, 'update'])->middleware('permiso:empleados.editar');
    Route::delete('/empleados/{empleado}', [EmpleadoController::class, 'destroy'])->middleware('permiso:empleados.baja');

    // Historial laboral: ceses, reintegros y cambios de contrato
    Route::get('/empleados/{empleado}/historial', [HistorialLaboralController::class, 'porEmpleado'])->middleware('permiso:empleados.ver_historial');
    Route::post('/empleados/{empleado}/cese', [HistorialLaboralController::class, 'cese'])->middleware('permiso:empleados.baja');
    Route::post('/empleados/{empleado}/reintegro', [HistorialLaboralController::class, 'reintegro'])->middleware('permiso:empleados.baja');
    Route::get('/historial-laboral', [HistorialLaboralController::class, 'index'])->middleware('permiso:movimientos.ver');
    Route::get('/historial-laboral/catalogos', [HistorialLaboralController::class, 'catalogos'])->middleware('permiso:movimientos.ver');
    Route::get('/historial-laboral/excel', [HistorialLaboralController::class, 'exportarExcel'])->middleware('permiso:movimientos.exportar');
    Route::patch('/historial-laboral/{id}/liquidacion', [HistorialLaboralController::class, 'liquidacion'])->middleware('permiso:movimientos.editar,empleados.baja');

    // Catálogos. Las listas las puede leer cualquier usuario: se usan en filtros y formularios de varios módulos.
    Route::get('departamentos', [DepartamentoController::class, 'index']);
    Route::get('departamentos/{departamento}', [DepartamentoController::class, 'show']);
    Route::post('departamentos', [DepartamentoController::class, 'store'])->middleware('permiso:departamentos.crear');
    Route::match(['put', 'patch'], 'departamentos/{departamento}', [DepartamentoController::class, 'update'])->middleware('permiso:departamentos.editar');
    Route::delete('departamentos/{departamento}', [DepartamentoController::class, 'destroy'])->middleware('permiso:departamentos.editar');
    Route::delete('departamentos/{id}/eliminar', [DepartamentoController::class, 'eliminar'])->middleware('permiso:departamentos.eliminar');

    Route::get('cargos', [CargoController::class, 'index']);
    Route::get('cargos/{cargo}', [CargoController::class, 'show']);
    Route::post('cargos', [CargoController::class, 'store'])->middleware('permiso:departamentos.crear');
    Route::match(['put', 'patch'], 'cargos/{cargo}', [CargoController::class, 'update'])->middleware('permiso:departamentos.editar');
    Route::delete('cargos/{cargo}', [CargoController::class, 'destroy'])->middleware('permiso:departamentos.editar');
    Route::delete('cargos/{id}/eliminar', [CargoController::class, 'eliminar'])->middleware('permiso:departamentos.eliminar');

    Route::get('bancos', [BancoController::class, 'index']);
    Route::get('bancos/{banco}', [BancoController::class, 'show']);
    Route::post('bancos', [BancoController::class, 'store'])->middleware('permiso:bancos.crear');
    Route::match(['put', 'patch'], 'bancos/{banco}', [BancoController::class, 'update'])->middleware('permiso:bancos.editar');
    Route::delete('bancos/{banco}', [BancoController::class, 'destroy'])->middleware('permiso:bancos.eliminar');

    // Aguinaldo (rutas específicas antes de la wildcard {nombre})
    Route::get('aguinaldo', [AguinaldoController::class, 'index'])->middleware('permiso:aguinaldo.ver');
    Route::post('aguinaldo', [AguinaldoController::class, 'store'])->middleware('permiso:aguinaldo.crear');
    Route::put('aguinaldo/fijos/{id}', [AguinaldoController::class, 'updateFijo'])->middleware('permiso:aguinaldo.editar');
    Route::put('aguinaldo/extras/{id}', [AguinaldoController::class, 'updateExtra'])->middleware('permiso:aguinaldo.editar');
    Route::get('aguinaldo/extras/{id}/quincenas', [AguinaldoController::class, 'quincenasExtra'])->whereNumber('id')->middleware('permiso:aguinaldo.ver');
    // {nombre} acepta "/" (where .*): las rutas más específicas van primero.
    Route::middleware('permiso:aguinaldo.exportar')->group(function () {
        Route::get('aguinaldo/{nombre}/pago/excel', [AguinaldoController::class, 'exportPagoGeneralExcel'])->where('nombre', '.*');
        Route::get('aguinaldo/{nombre}/bancos/excel', [AguinaldoController::class, 'exportBancosExcel'])->where('nombre', '.*');
        Route::get('aguinaldo/{nombre}/bancos/pdf', [AguinaldoController::class, 'exportBancosPdf'])->where('nombre', '.*');
        Route::get('aguinaldo/{nombre}/cheques/excel', [AguinaldoController::class, 'exportChequesExcel'])->where('nombre', '.*');
        Route::get('aguinaldo/{nombre}/cheques/pdf', [AguinaldoController::class, 'exportChequesPdf'])->where('nombre', '.*');
        Route::get('aguinaldo/{nombre}/pdf', [AguinaldoController::class, 'exportPdf'])->where('nombre', '.*');
        Route::get('aguinaldo/{nombre}/excel', [AguinaldoController::class, 'exportExcel'])->where('nombre', '.*');
    });
    Route::post('aguinaldo/{nombre}/cerrar', [AguinaldoController::class, 'cerrar'])->where('nombre', '.*')->middleware('permiso:aguinaldo.cerrar');
    Route::get('aguinaldo/{nombre}', [AguinaldoController::class, 'show'])->where('nombre', '.*')->middleware('permiso:aguinaldo.ver');
    Route::delete('aguinaldo/{nombre}', [AguinaldoController::class, 'destroy'])->where('nombre', '.*')->middleware('permiso:aguinaldo.eliminar');

    // Constancias
    Route::get('constancias/laboral/{id}/pdf', [ConstanciaController::class, 'laboral'])->middleware('permiso:constancias.laboral');
    Route::get('constancias/bancaria/{empleado}/{banco}/pdf', [ConstanciaController::class, 'bancaria'])->middleware('permiso:constancias.bancaria');
    Route::get('constancias/voucher/{empleado}/planillas', [ConstanciaController::class, 'vouchersDisponibles'])->middleware('permiso:constancias.voucher');
    Route::get('constancias/voucher/{empleado}/{planilla}/pdf', [ConstanciaController::class, 'voucher'])->middleware('permiso:constancias.voucher');

    // Incidencias
    Route::get('incidencias/{id}/pdf', [IncidenciaController::class, 'pdf'])->middleware('permiso:incidencias.ver');
    Route::post('incidencias/{id}/restaurar', [IncidenciaController::class, 'restaurar'])->middleware('permiso:incidencias.anular');
    Route::get('incidencias', [IncidenciaController::class, 'index'])->middleware('permiso:incidencias.ver');
    Route::post('incidencias', [IncidenciaController::class, 'store'])->middleware('permiso:incidencias.crear');
    Route::get('incidencias/{incidencia}', [IncidenciaController::class, 'show'])->middleware('permiso:incidencias.ver');
    Route::match(['put', 'patch'], 'incidencias/{incidencia}', [IncidenciaController::class, 'update'])->middleware('permiso:incidencias.editar');
    Route::delete('incidencias/{incidencia}', [IncidenciaController::class, 'destroy'])->middleware('permiso:incidencias.anular');

    // Vacaciones
    Route::get('vacaciones/saldo/{id}', [VacacionController::class, 'saldo'])->middleware('permiso:vacaciones.ver');
    Route::get('vacaciones/{id}/pdf', [VacacionController::class, 'pdf'])->middleware('permiso:vacaciones.ver');
    Route::post('vacaciones/{id}/restaurar', [VacacionController::class, 'restaurar'])->middleware('permiso:vacaciones.anular');
    Route::get('vacaciones', [VacacionController::class, 'index'])->middleware('permiso:vacaciones.ver');
    Route::post('vacaciones', [VacacionController::class, 'store'])->middleware('permiso:vacaciones.crear');
    Route::match(['put', 'patch'], 'vacaciones/{vacacione}', [VacacionController::class, 'update'])->middleware('permiso:vacaciones.editar');
    Route::delete('vacaciones/{vacacione}', [VacacionController::class, 'destroy'])->middleware('permiso:vacaciones.anular');

    // Planillas
    Route::middleware('permiso:planillas.exportar')->group(function () {
        Route::get('planillas/{planilla}/pdf', [PlanillaController::class, 'exportPdf']);
        Route::get('planillas/{planilla}/excel', [PlanillaController::class, 'exportExcel']);
        Route::get('planillas/{planilla}/pago/excel', [PlanillaController::class, 'exportPagoGeneralExcel']);
        Route::get('planillas/{planilla}/bancos/excel', [PlanillaController::class, 'exportBancosExcel']);
        Route::get('planillas/{planilla}/bancos/pdf', [PlanillaController::class, 'exportBancosPdf']);
        Route::get('planillas/{planilla}/cheques/excel', [PlanillaController::class, 'exportChequesExcel']);
        Route::get('planillas/{planilla}/cheques/pdf', [PlanillaController::class, 'exportChequesPdf']);
    });
    Route::post('planillas/{planilla}/actualizar-marcas', [PlanillaController::class, 'actualizarMarcas'])->middleware('permiso:planillas.editar');
    Route::post('planillas/{planilla}/cerrar', [PlanillaController::class, 'cerrar'])->middleware('permiso:planillas.cerrar');
    Route::put('planillas/{planilla}/detalles/{detalle}', [PlanillaController::class, 'updateDetalle'])->middleware('permiso:planillas.editar');
    Route::get('planillas', [PlanillaController::class, 'index'])->middleware('permiso:planillas.ver');
    Route::post('planillas', [PlanillaController::class, 'store'])->middleware('permiso:planillas.crear');
    Route::get('planillas/{planilla}', [PlanillaController::class, 'show'])->middleware('permiso:planillas.ver');
    Route::delete('planillas/{planilla}', [PlanillaController::class, 'destroy'])->middleware('permiso:planillas.eliminar');

    // Ingresos/deducciones recurrentes (préstamos, adelantos, bonos fijos)
    Route::patch('ingresos-deducciones/{id}/estado', [IngresoDeduccionController::class, 'cambiarEstado'])->middleware('permiso:ingresos_deducciones.editar');
    Route::get('ingresos-deducciones', [IngresoDeduccionController::class, 'index'])->middleware('permiso:ingresos_deducciones.ver');
    Route::post('ingresos-deducciones', [IngresoDeduccionController::class, 'store'])->middleware('permiso:ingresos_deducciones.crear');
    Route::get('ingresos-deducciones/{ingresos_deduccione}', [IngresoDeduccionController::class, 'show'])->middleware('permiso:ingresos_deducciones.ver');
    Route::match(['put', 'patch'], 'ingresos-deducciones/{ingresos_deduccione}', [IngresoDeduccionController::class, 'update'])->middleware('permiso:ingresos_deducciones.editar');
    Route::delete('ingresos-deducciones/{ingresos_deduccione}', [IngresoDeduccionController::class, 'destroy'])->middleware('permiso:ingresos_deducciones.eliminar');

    // Campos Variables: los valores los leen otros módulos (ej. salario mínimo en Empleados).
    Route::get('campos-variables', [CamposVariablesController::class, 'index']);
    Route::put('campos-variables', [CamposVariablesController::class, 'update'])->middleware('permiso:campos_variables.editar');

    // Estadística laboral
    Route::get('estadistica-laboral', [EstadisticaLaboralController::class, 'index'])->middleware('permiso:estadistica.ver');
    Route::get('estadistica-laboral/pdf', [EstadisticaLaboralController::class, 'exportPdf'])->middleware('permiso:estadistica.exportar');
    Route::get('estadistica-laboral/{empleado}', [EstadisticaLaboralController::class, 'show'])->middleware('permiso:estadistica.ver');

    // Salarios devengados de varios o todos los empleados en un rango de fechas
    Route::get('salarios-devengados', [SalariosDevengadosController::class, 'index'])->middleware('permiso:salarios_devengados.ver');
    Route::get('salarios-devengados/excel', [SalariosDevengadosController::class, 'excel'])->middleware('permiso:salarios_devengados.exportar');
    Route::get('listado-cumpleanos', [ListadoCumpleanosController::class, 'index'])->middleware('permiso:listado_cumpleanos.ver');
    Route::get('listado-cumpleanos/excel', [ListadoCumpleanosController::class, 'excel'])->middleware('permiso:listado_cumpleanos.exportar');

    // Bitácora. Constancias muestra su propio historial (solo el módulo Constancias, ver LogSistemaController).
    Route::get('bitacora', [LogSistemaController::class, 'index'])
        ->middleware('permiso:bitacora.ver,constancias.laboral,constancias.bancaria,constancias.voucher');
    Route::get('bitacora/pdf', [LogSistemaController::class, 'exportPdf'])->middleware('permiso:bitacora.exportar');

    // Gestión de usuarios
    Route::get('usuarios/roles', [UsuarioController::class, 'roles'])->middleware('permiso:usuarios.ver');
    Route::get('usuarios', [UsuarioController::class, 'index'])->middleware('permiso:usuarios.ver');
    Route::post('usuarios', [UsuarioController::class, 'store'])->middleware('permiso:usuarios.crear');
    Route::match(['put', 'patch'], 'usuarios/{usuario}', [UsuarioController::class, 'update'])->middleware('permiso:usuarios.editar');
    Route::delete('usuarios/{usuario}', [UsuarioController::class, 'destroy'])->middleware('permiso:usuarios.eliminar');
    Route::patch('usuarios/{id}/estado', [UsuarioController::class, 'cambiarEstado'])->middleware('permiso:usuarios.editar');

    // Gestión de roles
    Route::get('roles/catalogo', [RolController::class, 'catalogo'])->middleware('permiso:roles.ver');
    Route::get('roles', [RolController::class, 'index'])->middleware('permiso:roles.ver');
    Route::post('roles', [RolController::class, 'store'])->middleware('permiso:roles.crear');
    Route::put('roles/{rol}', [RolController::class, 'update'])->middleware('permiso:roles.editar');
    Route::delete('roles/{rol}', [RolController::class, 'destroy'])->middleware('permiso:roles.eliminar');
});
