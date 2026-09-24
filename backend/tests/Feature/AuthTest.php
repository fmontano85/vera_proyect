<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Hash;

/**
 * EnsureFrontendRequestsAreStateful (Sanctum SPA) solo arranca la sesion
 * si el request trae Origin/Referer de un dominio listado en
 * SANCTUM_STATEFUL_DOMAINS - un navegador real lo manda solo en un
 * request cross-origin con credentials, el cliente de test no, hay que
 * simularlo. Ademas, un request stateful real pasa por
 * ValidateCsrfToken (parte del stack "web" que ese middleware activa)
 * - el navegador maneja esa cookie/header solo via el handshake de GET
 * /sanctum/csrf-cookie. Se excluye aqui a proposito: lo que interesa
 * verificar en este archivo es la logica de AuthController, no
 * reimplementar el mecanismo de CSRF de Laravel (eso ya esta testeado por
 * el framework).
 */
function comoFrontend(): Tests\TestCase
{
    return test()
        ->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class)
        ->withHeader('referer', 'http://localhost:5173');
}

it('inicia sesion con credenciales validas y devuelve los roles', function () {
    $this->seed(Database\Seeders\RoleSeeder::class);
    $user = User::factory()->create(['password' => Hash::make('password-valido')]);
    $user->assignRole('analista');

    $response = comoFrontend()->postJson('/api/login', [
        'email' => $user->email,
        'password' => 'password-valido',
    ]);

    $response->assertOk()
        ->assertJsonPath('id', $user->id)
        ->assertJsonPath('roles.0', 'analista');
    $this->assertAuthenticatedAs($user);
});

it('rechaza credenciales invalidas sin revelar si el email existe', function () {
    $user = User::factory()->create(['password' => Hash::make('password-valido')]);

    $response = comoFrontend()->postJson('/api/login', [
        'email' => $user->email,
        'password' => 'password-incorrecto',
    ]);

    $response->assertStatus(422);
    $this->assertGuest();
});

it('aplica rate limiting despues de varios intentos fallidos', function () {
    $user = User::factory()->create(['password' => Hash::make('password-valido')]);

    for ($i = 0; $i < 5; $i++) {
        comoFrontend()->postJson('/api/login', ['email' => $user->email, 'password' => 'mal']);
    }

    comoFrontend()->postJson('/api/login', ['email' => $user->email, 'password' => 'mal'])
        ->assertStatus(429);
});

it('cierra sesion', function () {
    // actingAs() es un atajo de testing que fija el usuario a nivel de
    // contenedor sin pasar por una sesion real - invalidar esa sesion no
    // lo revierte. Se entra por el login real para que logout() tenga una
    // sesion autentica que sí pueda invalidar.
    $user = User::factory()->create(['password' => Hash::make('password-valido')]);
    $client = comoFrontend();
    $client->postJson('/api/login', ['email' => $user->email, 'password' => 'password-valido'])
        ->assertOk();

    $client->postJson('/api/logout')->assertNoContent();
    // 'web', explicito: el middleware auth:sanctum deja el guard "default"
    // en 'sanctum' para el resto del request (y del test, mismo contenedor) -
    // assertGuest() sin argumento revisaria ese guard, que cachea su propia
    // resolucion y no refleja el logout() de 'web' hecho en el controlador.
    $this->assertGuest('web');
});
