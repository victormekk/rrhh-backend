<?php

use App\Http\Controllers\AguinaldoController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BancoController;
use App\Http\Controllers\CamposVariablesController;
use App\Http\Controllers\ConstanciaController;
use App\Http\Controllers\CumpleanosController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EstadisticaLaboralController;
use App\Http\Controllers\DepartamentoController;
use App\Http\Controllers\EmpleadoController;
use App\Http\Controllers\IncidenciaController;
use App\Http\Controllers\LogSistemaController;
use App\Http\Controllers\PlanillaController;
use App\Http\Controllers\CargoController;
use App\Http\Controllers\UsuarioController;
use App\Http\Controllers\VacacionController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);

    Route::get('/dashboard/stats',            [DashboardController::class, 'stats']);
    Route::get('/dashboard/planillas-chart', [DashboardController::class, 'chartPlanillas']);

    // Cumpleaños
    Route::get('/cumpleanos', [CumpleanosController::class, 'index']);

    // Empleados
    Route::post('/empleados/{empleado}/foto', [EmpleadoController::class, 'uploadFoto']);
    Route::apiResource('empleados', EmpleadoController::class);

    // Catálogos
    Route::apiResource('departamentos', DepartamentoController::class);
    Route::delete('departamentos/{id}/eliminar', [DepartamentoController::class, 'eliminar']);
    Route::apiResource('cargos', CargoController::class);
    Route::delete('cargos/{id}/eliminar', [CargoController::class, 'eliminar']);
    Route::apiResource('bancos', BancoController::class);

    // Aguinaldo (rutas específicas antes de la wildcard {nombre})
    Route::get('aguinaldo',                [AguinaldoController::class, 'index']);
    Route::post('aguinaldo',               [AguinaldoController::class, 'store']);
    Route::put('aguinaldo/fijos/{id}',     [AguinaldoController::class, 'updateFijo']);
    Route::put('aguinaldo/extras/{id}',    [AguinaldoController::class, 'updateExtra']);
    Route::get('aguinaldo/{nombre}/pdf',   [AguinaldoController::class, 'exportPdf'])->where('nombre', '.*');
    Route::post('aguinaldo/{nombre}/cerrar',[AguinaldoController::class, 'cerrar'])->where('nombre', '.*');
    Route::get('aguinaldo/{nombre}',       [AguinaldoController::class, 'show'])->where('nombre', '.*');
    Route::delete('aguinaldo/{nombre}',    [AguinaldoController::class, 'destroy'])->where('nombre', '.*');

    // Constancias
    Route::get('constancias/laboral/{id}/pdf', [ConstanciaController::class, 'laboral']);
    Route::get('constancias/voucher/{empleado}/planillas', [ConstanciaController::class, 'vouchersDisponibles']);
    Route::get('constancias/voucher/{empleado}/{planilla}/pdf', [ConstanciaController::class, 'voucher']);

    // Incidencias
    Route::get('incidencias/{id}/pdf', [IncidenciaController::class, 'pdf']);
    Route::apiResource('incidencias', IncidenciaController::class);

    // Vacaciones
    Route::get('vacaciones/saldo/{id}',  [VacacionController::class, 'saldo']);
    Route::get('vacaciones/{id}/pdf',    [VacacionController::class, 'pdf']);
    Route::apiResource('vacaciones',     VacacionController::class)->except(['show']);

    // Planillas
    Route::get('planillas/{planilla}/pdf', [PlanillaController::class, 'exportPdf']);
    Route::get('planillas/{planilla}/excel', [PlanillaController::class, 'exportExcel']);
    Route::get('planillas/{planilla}/pago', [PlanillaController::class, 'exportPago']);
    Route::post('planillas/{planilla}/cerrar', [PlanillaController::class, 'cerrar']);
    Route::delete('planillas/{id}/eliminar-cerrada', [PlanillaController::class, 'eliminarCerrada']);
    Route::put('planillas/{planilla}/detalles/{detalle}', [PlanillaController::class, 'updateDetalle']);
    Route::apiResource('planillas', PlanillaController::class)->except(['update']);

    // Campos Variables
    Route::get('campos-variables',  [CamposVariablesController::class, 'index']);
    Route::put('campos-variables',  [CamposVariablesController::class, 'update']);

    // Estadística laboral
    Route::get('estadistica-laboral',         [EstadisticaLaboralController::class, 'index']);
    Route::get('estadistica-laboral/pdf',     [EstadisticaLaboralController::class, 'exportPdf']);
    Route::get('estadistica-laboral/{empleado}', [EstadisticaLaboralController::class, 'show']);

    // Log del sistema
    Route::get('log-sistema',     [LogSistemaController::class, 'index']);
    Route::get('log-sistema/pdf', [LogSistemaController::class, 'exportPdf']);

    // Gestión de usuarios (solo admin)
    Route::apiResource('usuarios', UsuarioController::class)->except(['show']);
});
