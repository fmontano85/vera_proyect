<?php

declare(strict_types=1);

use App\Http\Controllers\SubjectController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

/*
|--------------------------------------------------------------------------
| Rutas de tenant
|--------------------------------------------------------------------------
|
| Toda ruta de negocio (subjects, sources, matches, evidencia, reportes...)
| va dentro de este grupo: resuelve el tenant desde el usuario autenticado
| (App\Http\Middleware\InitializeTenancyFromAuthenticatedUser) antes de que
| el controlador toque cualquier modelo con global scope de tenant_id.
|
*/
Route::middleware(['auth:sanctum', 'tenant'])->group(function () {
    Route::apiResource('subjects', SubjectController::class)->only(['index', 'store', 'show']);
    Route::post('subjects/{subject}/buscar', [SubjectController::class, 'buscar']);
    Route::get('subjects/{subject}/matches', [SubjectController::class, 'matches']);
});
