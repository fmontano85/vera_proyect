<?php

declare(strict_types=1);

use App\Http\Controllers\AuthController;
use App\Http\Controllers\MatchController;
use App\Http\Controllers\SearchResultController;
use App\Http\Controllers\SubjectController;
use Illuminate\Support\Facades\Route;

// throttle:5,1 (OWASP A07 - fuerza bruta): 5 intentos por minuto por IP+email.
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1');
Route::middleware('auth:sanctum')->post('/logout', [AuthController::class, 'logout']);

Route::middleware('auth:sanctum')->get('/user', [AuthController::class, 'me']);

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
    Route::post('matches/{match}/proponer', [MatchController::class, 'proponer']);
    Route::post('matches/{match}/resolver', [MatchController::class, 'resolver']);

    // Flujo bajo demanda (seccion 3.7 del CLAUDE.md raiz, 2026-09-24).
    Route::get('subjects/{subject}/resultados', [SearchResultController::class, 'index']);
    Route::post('resultados/{resultado}/extraer', [SearchResultController::class, 'extraer']);
    Route::post('resultados/{resultado}/descartar', [SearchResultController::class, 'descartar']);
    Route::post('resultados/{resultado}/captura-manual', [SearchResultController::class, 'capturaManual']);
});
