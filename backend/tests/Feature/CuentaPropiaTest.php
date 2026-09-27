<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Stancl\Tenancy\Database\Models\Tenant;

function cuenta(string $rol = 'analista', bool $conTenant = true): User
{
    $user = User::factory()->create(['password' => Hash::make('clave-actual-123')]);
    if ($conTenant) {
        $user->forceFill(['tenant_id' => Tenant::create()->id])->save();
    }
    $user->assignRole($rol);

    return $user;
}

beforeEach(fn () => $this->seed(RoleSeeder::class));

it('cambia la contrasena con la actual correcta y cierra las demas sesiones', function () {
    $user = cuenta();
    DB::table('sessions')->insert(['id' => 'otra', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);

    $this->actingAs($user)->postJson('/api/cuenta/contrasena', [
        'contrasena_actual' => 'clave-actual-123',
        'contrasena' => 'otra-clave-larga-456',
        'contrasena_confirmation' => 'otra-clave-larga-456',
    ])->assertNoContent();

    expect(Hash::check('otra-clave-larga-456', $user->refresh()->password))->toBeTrue();
    expect(DB::table('sessions')->where('id', 'otra')->exists())->toBeFalse();
});

it('rechaza la contrasena actual incorrecta, la confirmacion distinta y una clave debil', function () {
    $user = cuenta();
    $ok = ['contrasena_actual' => 'clave-actual-123', 'contrasena' => 'otra-clave-larga-456', 'contrasena_confirmation' => 'otra-clave-larga-456'];

    $this->actingAs($user)->postJson('/api/cuenta/contrasena', [...$ok, 'contrasena_actual' => 'mal'])->assertUnprocessable()->assertJsonValidationErrors('contrasena_actual');
    $this->actingAs($user)->postJson('/api/cuenta/contrasena', [...$ok, 'contrasena_confirmation' => 'distinta'])->assertUnprocessable()->assertJsonValidationErrors('contrasena');
    $this->actingAs($user)->postJson('/api/cuenta/contrasena', [...$ok, 'contrasena' => 'corta', 'contrasena_confirmation' => 'corta'])->assertUnprocessable();
    $this->actingAs($user)->postJson('/api/cuenta/contrasena', [...$ok, 'contrasena' => 'clave-actual-123', 'contrasena_confirmation' => 'clave-actual-123'])->assertUnprocessable();

    expect(Hash::check('clave-actual-123', $user->refresh()->password))->toBeTrue();
});

it('cualquier rol autenticado puede cambiar su contrasena, incluido superadmin', function () {
    $super = cuenta('superadmin', conTenant: false);

    $this->actingAs($super)->postJson('/api/cuenta/contrasena', [
        'contrasena_actual' => 'clave-actual-123', 'contrasena' => 'otra-clave-larga-456', 'contrasena_confirmation' => 'otra-clave-larga-456',
    ])->assertNoContent();
});

it('exige autenticacion y tiene limite de intentos', function () {
    $this->postJson('/api/cuenta/contrasena', [])->assertUnauthorized();

    $user = cuenta();
    foreach (range(1, 5) as $i) {
        $this->actingAs($user)->postJson('/api/cuenta/contrasena', ['contrasena_actual' => 'mal']);
    }
    $this->actingAs($user)->postJson('/api/cuenta/contrasena', ['contrasena_actual' => 'mal'])->assertTooManyRequests();
});

it('actualiza el nombre propio sin tocar rol, email ni tenant', function () {
    $user = cuenta();
    $tenantId = $user->tenant_id;

    $this->actingAs($user)->patchJson('/api/cuenta', ['name' => 'Nuevo Nombre', 'email' => 'otro@x.test', 'tenant_id' => 'zzz'])->assertOk();

    $user->refresh();
    expect($user->name)->toBe('Nuevo Nombre')->and($user->tenant_id)->toBe($tenantId)->and($user->email)->not->toBe('otro@x.test');
});
