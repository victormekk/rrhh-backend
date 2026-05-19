<?php

use App\Http\Controllers\AguinaldoController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\IncidenciaController;
use App\Http\Controllers\BancoController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DepartamentoController;
use App\Http\Controllers\EmpleadoController;
use App\Http\Controllers\PlanillaController;
use App\Http\Controllers\PuestoController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);

    Route::get('/dashboard/stats', [DashboardController::class, 'stats']);

    // Empleados
    Route::post('/empleados/{empleado}/foto', [EmpleadoController::class, 'uploadFoto']);
    Route::apiResource('empleados', EmpleadoController::class);

    // Catálogos
    Route::apiResource('departamentos', DepartamentoController::class);
    Route::apiResource('puestos', PuestoController::class);
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

    // Incidencias
    Route::apiResource('incidencias', IncidenciaController::class);

    // Planillas
    Route::get('planillas/{planilla}/pdf', [PlanillaController::class, 'exportPdf']);
    Route::post('planillas/{planilla}/cerrar', [PlanillaController::class, 'cerrar']);
    Route::put('planillas/{planilla}/detalles/{detalle}', [PlanillaController::class, 'updateDetalle']);
    Route::apiResource('planillas', PlanillaController::class)->except(['update']);
});
