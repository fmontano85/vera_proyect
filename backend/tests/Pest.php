<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Stancl\Tenancy\Database\Models\Tenant;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class)->in('Feature');
uses(TestCase::class)->in('Unit');

/**
 * Usuario de un tenant con un rol, para cualquier Feature test. Antes
 * repetido con 7 nombres distintos (usuarioTenant, usuarioSanciones,
 * usuarioEvidencia, usuarioGestionTags, usuarioHistorial,
 * usuarioDashboardConRol, usuarioListaConRol) - un solo punto para el
 * dia en que User necesite un atributo mas o cambie el mecanismo de
 * asignacion de tenant.
 */
/** Sanciones deshabilitada por defecto (decision del usuario 2026-09-28,
 * solo el superadmin la activa por tenant): los tests que ejercitan la
 * funcion la habilitan explicito con este helper en vez de repetir
 * `->forceFill(['sanciones_habilitado' => true])->save()` en cada uno. */
function tenantConSanciones(): Tenant
{
    $tenant = Tenant::create();
    $tenant->forceFill(['sanciones_habilitado' => true])->save();

    return $tenant;
}

function usuarioDeTenant(Tenant $tenant, string $rol, array $atributos = []): User
{
    $user = User::factory()->create($atributos);
    $user->forceFill(['tenant_id' => $tenant->id])->save();
    $user->assignRole($rol);

    return $user;
}

/**
 * EnsureFrontendRequestsAreStateful (Sanctum SPA) solo arranca la sesion
 * si el request trae Origin/Referer de un dominio listado en
 * SANCTUM_STATEFUL_DOMAINS - un navegador real lo manda solo en un
 * request cross-origin con credentials, el cliente de test no, hay que
 * simularlo. Ademas, un request stateful real pasa por
 * ValidateCsrfToken (parte del stack "web" que ese middleware activa) -
 * el navegador maneja esa cookie/header solo via el handshake de GET
 * /sanctum/csrf-cookie. Se excluye aqui a proposito: para probar
 * POST /api/login en un test interesa la logica de AuthController, no
 * reimplementar el mecanismo de CSRF de Laravel (eso ya esta testeado
 * por el framework). Antes vivia duplicada solo en AuthTest.php.
 */
function comoFrontend(): Tests\TestCase
{
    return test()
        ->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class)
        ->withHeader('referer', 'http://localhost:5173');
}
