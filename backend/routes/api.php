<?php

declare(strict_types=1);

use App\Http\Controllers\AuthController;
use App\Http\Controllers\BitacoraController;
use App\Http\Controllers\CoincidenciaController;
use App\Http\Controllers\CuentaController;
use App\Http\Controllers\DocumentoLegalController;
use App\Http\Controllers\DocumentoLegalSuperadminController;
use App\Http\Controllers\ConfiguracionController;
use App\Http\Controllers\InicioController;
use App\Http\Controllers\MatchController;
use App\Http\Controllers\SancionController;
use App\Http\Controllers\SearchResultController;
use App\Http\Controllers\SearchTagController;
use App\Http\Controllers\SeguimientoController;
use App\Http\Controllers\SubjectAliasController;
use App\Http\Controllers\SubjectController;
use App\Http\Controllers\SuperadminController;
use App\Http\Controllers\TagSearchController;
use App\Http\Controllers\UsuarioController;
use Illuminate\Support\Facades\Route;

// throttle:5,1 (OWASP A07 - fuerza bruta): 5 intentos por minuto por IP+email.
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1');
Route::middleware('auth:sanctum')->post('/logout', [AuthController::class, 'logout']);

Route::middleware(['auth:sanctum', 'activo'])->get('/user', [AuthController::class, 'me']);

// Cuenta propia: cualquier usuario autenticado (incluido superadmin), sin tenant.
Route::middleware(['auth:sanctum', 'activo'])->group(function () {
    Route::patch('cuenta', [CuentaController::class, 'actualizar']);
    Route::post('cuenta/contrasena', [CuentaController::class, 'cambiarContrasena'])->middleware('throttle:5,1');
});

/*
|--------------------------------------------------------------------------
| Panel de superadmin (seccion 3.2 - fuera de las rutas de tenant)
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum', 'activo'])->prefix('superadmin')->group(function () {
    Route::get('tenants', [SuperadminController::class, 'tenants']);
    Route::post('tenants', [SuperadminController::class, 'crearTenant']);
    Route::patch('tenants/{tenant}', [SuperadminController::class, 'actualizarTenant']);
    Route::get('configuracion-sanciones', [SuperadminController::class, 'verConfiguracionSanciones']);
    Route::put('configuracion-sanciones', [SuperadminController::class, 'actualizarConfiguracionSanciones']);
    Route::post('sanciones/actualizar-lista', [SuperadminController::class, 'actualizarListaSanciones']);
    Route::get('bitacora', [BitacoraController::class, 'global']);
    Route::get('bitacora/eventos', [BitacoraController::class, 'eventosGlobal']);

    // Terminos y contrato de encargo (seccion 3.9, punto 1).
    Route::get('documentos-legales', [DocumentoLegalSuperadminController::class, 'index']);
    Route::post('documentos-legales', [DocumentoLegalSuperadminController::class, 'store']);
    Route::put('documentos-legales/{documento}', [DocumentoLegalSuperadminController::class, 'update']);
    Route::delete('documentos-legales/{documento}', [DocumentoLegalSuperadminController::class, 'destroy']);
    Route::post('documentos-legales/{documento}/publicar', [DocumentoLegalSuperadminController::class, 'publicar']);
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
    // 'documentos' (seccion 3.9, punto 1): sin aceptar los terminos vigentes
    // el tenant no carga personas ni busca; consultar sigue permitido.
    Route::apiResource('subjects', SubjectController::class)->only(['index', 'store', 'show', 'update'])
        ->middlewareFor('store', 'documentos');
    Route::get('subjects/{subject}/historial', [SubjectController::class, 'historial']);
    Route::post('subjects/{subject}/buscar', [SubjectController::class, 'buscar'])->middleware('documentos');
    Route::get('subjects/{subject}/matches', [SubjectController::class, 'matches']);
    Route::post('subjects/{subject}/aliases', [SubjectAliasController::class, 'store'])->middleware('documentos');
    Route::delete('subjects/{subject}/aliases/{alias}', [SubjectAliasController::class, 'destroy'])->scopeBindings();

    // Hallazgos contra listas de sanciones (OFAC SDN).
    Route::get('sanciones', [SancionController::class, 'index']);
    Route::post('sanciones/{sancion}/resolver', [SancionController::class, 'resolver']);
    Route::post('subjects/{subject}/sanciones/cruzar', [SancionController::class, 'cruzar'])->middleware('documentos');

    // Documentos legales vigentes: todos los leen, el admin los acepta.
    Route::get('documentos-legales', [DocumentoLegalController::class, 'index']);
    Route::post('documentos-legales/{documento}/aceptar', [DocumentoLegalController::class, 'aceptar']);

    // Usuarios del tenant (solo admin, UsuarioPolicy).
    Route::get('usuarios', [UsuarioController::class, 'index']);
    Route::post('usuarios', [UsuarioController::class, 'store']);
    Route::patch('usuarios/{usuario}', [UsuarioController::class, 'update']);

    // Bitacora del tenant (solo admin, seccion 3.9 punto 7).
    Route::get('bitacora', [BitacoraController::class, 'delTenant']);
    Route::get('bitacora/eventos', [BitacoraController::class, 'eventosDelTenant']);

    // Inicio y dashboard de coincidencias pendientes (Fase 2).
    Route::get('inicio/resumen', [InicioController::class, 'resumen']);
    Route::get('coincidencias', [CoincidenciaController::class, 'index']);
    Route::post('matches/{match}/proponer', [MatchController::class, 'proponer']);
    Route::post('matches/{match}/resolver', [MatchController::class, 'resolver']);

    // Flujo bajo demanda (seccion 3.7 del CLAUDE.md raiz, 2026-09-24).
    Route::get('subjects/{subject}/resultados', [SearchResultController::class, 'index']);
    Route::post('resultados/{resultado}/extraer', [SearchResultController::class, 'extraer'])->middleware('documentos');
    Route::post('resultados/{resultado}/descartar', [SearchResultController::class, 'descartar']);
    Route::get('resultados/{resultado}/evidencia/{tipo}', [SearchResultController::class, 'evidencia']);
    Route::post('resultados/{resultado}/captura-manual', [SearchResultController::class, 'capturaManual'])->middleware('documentos');

    // Busqueda por tags (sesion posterior a la 3.7, sin subject).
    Route::get('tags-busqueda', [SearchTagController::class, 'index']);
    Route::post('tags-busqueda', [SearchTagController::class, 'store']);
    Route::patch('tags-busqueda/{tag}', [SearchTagController::class, 'update']);
    Route::post('busquedas-tags', [TagSearchController::class, 'buscar'])->middleware('documentos');
    Route::get('busquedas-tags/resultados', [TagSearchController::class, 'resultados']);

    // Agenda de seguimiento de la lista de vigilancia (seccion 3.8, Fase 2).
    Route::get('seguimientos', [SeguimientoController::class, 'index']);
    Route::post('subjects/{subject}/seguimiento-realizado', [SeguimientoController::class, 'realizado']);
    Route::get('configuracion/frecuencias-seguimiento', [ConfiguracionController::class, 'frecuencias']);
    Route::put('configuracion/frecuencias-seguimiento', [ConfiguracionController::class, 'actualizarFrecuencias']);
});
