<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Activitylog\Models\Activity;
use Stancl\Tenancy\Database\Models\Tenant;

function usuarioTenant(Tenant $tenant, string $rol, array $atributos = []): User
{
    $user = User::factory()->create($atributos);
    $user->forceFill(['tenant_id' => $tenant->id])->save();
    $user->assignRole($rol);

    return $user;
}

const CLAVE_VALIDA = 'una-clave-larga-123';

beforeEach(fn () => $this->seed(RoleSeeder::class));

it('solo admin gestiona usuarios', function (string $rol) {
    $tenant = Tenant::create();
    $user = usuarioTenant($tenant, $rol);

    $this->actingAs($user)->getJson('/api/usuarios')->assertForbidden();
    $this->actingAs($user)->postJson('/api/usuarios', [])->assertForbidden();
    $this->actingAs($user)->patchJson("/api/usuarios/{$user->id}", ['name' => 'x'])->assertForbidden();
})->with(['oficial_cumplimiento', 'analista', 'lectura']);

it('lista solo los usuarios del propio tenant, con rol y estado, sin datos sensibles', function () {
    $tenant = Tenant::create();
    $otro = Tenant::create();
    $admin = usuarioTenant($tenant, 'admin');
    usuarioTenant($tenant, 'analista', ['name' => 'Zoe Analista']);
    usuarioTenant($otro, 'admin', ['name' => 'Intruso Ajeno']);

    $respuesta = $this->actingAs($admin)->getJson('/api/usuarios')->assertOk();

    $nombres = collect($respuesta->json())->pluck('name');
    expect($nombres)->toContain('Zoe Analista')->not->toContain('Intruso Ajeno');
    expect($respuesta->json('0'))->toHaveKeys(['id', 'name', 'email', 'rol', 'activo']);
    expect($respuesta->getContent())->not->toContain('password')->not->toContain('remember_token');
});

it('admin crea un usuario en su tenant con rol y contrasena; queda auditado sin la contrasena', function () {
    $tenant = Tenant::create();
    $admin = usuarioTenant($tenant, 'admin');

    $respuesta = $this->actingAs($admin)->postJson('/api/usuarios', [
        'name' => 'Nuevo Analista',
        'email' => 'nuevo@vera.test',
        'password' => CLAVE_VALIDA,
        'rol' => 'analista',
    ])->assertCreated()->assertJsonPath('rol', 'analista')->assertJsonPath('activo', true);

    $nuevo = User::findOrFail($respuesta->json('id'));
    expect($nuevo->tenant_id)->toBe($tenant->id)
        ->and($nuevo->hasRole('analista'))->toBeTrue()
        ->and(Hash::check(CLAVE_VALIDA, $nuevo->password))->toBeTrue();

    $log = Activity::where('subject_type', User::class)->where('subject_id', $nuevo->id)->get();
    expect($log)->not->toBeEmpty();
    expect(json_encode($log->toArray()))->not->toContain(CLAVE_VALIDA)->not->toContain($nuevo->password);
});

it('nunca deja crear un superadmin ni un rol inexistente, ni fijar el tenant desde el body', function () {
    $tenant = Tenant::create();
    $otro = Tenant::create();
    $admin = usuarioTenant($tenant, 'admin');

    $base = ['name' => 'X', 'email' => 'x@vera.test', 'password' => CLAVE_VALIDA];
    $this->actingAs($admin)->postJson('/api/usuarios', [...$base, 'rol' => 'superadmin'])->assertUnprocessable();
    $this->actingAs($admin)->postJson('/api/usuarios', [...$base, 'rol' => 'inventado'])->assertUnprocessable();

    $creado = $this->actingAs($admin)->postJson('/api/usuarios', [...$base, 'rol' => 'lectura', 'tenant_id' => $otro->id])->assertCreated();
    expect(User::find($creado->json('id'))->tenant_id)->toBe($tenant->id);
});

it('exige contrasena fuerte y email unico', function () {
    $tenant = Tenant::create();
    $admin = usuarioTenant($tenant, 'admin');
    $existente = usuarioTenant($tenant, 'lectura');

    $base = ['name' => 'X', 'email' => 'x@vera.test', 'rol' => 'lectura'];
    $this->actingAs($admin)->postJson('/api/usuarios', [...$base, 'password' => 'corta1'])->assertUnprocessable()->assertJsonValidationErrors('password');
    $this->actingAs($admin)->postJson('/api/usuarios', [...$base, 'password' => 'solo-letras-largas'])->assertUnprocessable()->assertJsonValidationErrors('password');
    $this->actingAs($admin)->postJson('/api/usuarios', [...$base, 'password' => CLAVE_VALIDA, 'email' => $existente->email])
        ->assertUnprocessable()->assertJsonValidationErrors('email');
});

it('admin cambia el rol, nombre y contrasena de otro usuario; el cambio de rol y el reinicio quedan auditados', function () {
    $tenant = Tenant::create();
    $admin = usuarioTenant($tenant, 'admin');
    $objetivo = usuarioTenant($tenant, 'lectura');

    $this->actingAs($admin)->patchJson("/api/usuarios/{$objetivo->id}", [
        'name' => 'Nombre Nuevo', 'rol' => 'oficial_cumplimiento', 'password' => CLAVE_VALIDA,
    ])->assertOk()->assertJsonPath('rol', 'oficial_cumplimiento');

    $objetivo->refresh();
    expect($objetivo->hasRole('oficial_cumplimiento'))->toBeTrue()->and($objetivo->hasRole('lectura'))->toBeFalse()
        ->and(Hash::check(CLAVE_VALIDA, $objetivo->password))->toBeTrue();

    $eventos = Activity::where('subject_type', User::class)->where('subject_id', $objetivo->id)->pluck('event');
    expect($eventos)->toContain('rol_cambiado')->toContain('contrasena_restablecida');
});

it('desactivar cierra sus sesiones y tokens; un usuario inactivo no puede iniciar sesion ni usar la API', function () {
    $tenant = Tenant::create();
    $admin = usuarioTenant($tenant, 'admin');
    $objetivo = usuarioTenant($tenant, 'analista', ['password' => Hash::make(CLAVE_VALIDA)]);
    $objetivo->createToken('t');
    DB::table('sessions')->insert(['id' => 'abc', 'user_id' => $objetivo->id, 'payload' => '', 'last_activity' => time()]);

    $this->actingAs($admin)->patchJson("/api/usuarios/{$objetivo->id}", ['activo' => false])->assertOk()->assertJsonPath('activo', false);

    expect(DB::table('sessions')->where('user_id', $objetivo->id)->count())->toBe(0)
        ->and($objetivo->tokens()->count())->toBe(0);

    $this->postJson('/api/login', ['email' => $objetivo->email, 'password' => CLAVE_VALIDA])->assertUnprocessable();
    $this->actingAs($objetivo->refresh())->getJson('/api/subjects')->assertForbidden();
});

it('un admin no puede quitarse su propio rol ni desactivarse (evita quedar sin acceso)', function () {
    $tenant = Tenant::create();
    $admin = usuarioTenant($tenant, 'admin');

    $this->actingAs($admin)->patchJson("/api/usuarios/{$admin->id}", ['rol' => 'lectura'])->assertUnprocessable();
    $this->actingAs($admin)->patchJson("/api/usuarios/{$admin->id}", ['activo' => false])->assertUnprocessable();
    $this->actingAs($admin)->patchJson("/api/usuarios/{$admin->id}", ['name' => 'Mi Nombre'])->assertOk();
});

it('no se puede tocar a un usuario de otro tenant ni a un superadmin', function () {
    $tenant = Tenant::create();
    $otro = Tenant::create();
    $admin = usuarioTenant($tenant, 'admin');
    $ajeno = usuarioTenant($otro, 'analista');
    $super = User::factory()->create();
    $super->assignRole('superadmin');

    $this->actingAs($admin)->patchJson("/api/usuarios/{$ajeno->id}", ['activo' => false])->assertNotFound();
    $this->actingAs($admin)->patchJson("/api/usuarios/{$super->id}", ['activo' => false])->assertNotFound();
    expect($ajeno->refresh()->activo)->toBeTrue();
});

it('un PATCH vacio o sin campos validos se rechaza', function () {
    $tenant = Tenant::create();
    $admin = usuarioTenant($tenant, 'admin');
    $objetivo = usuarioTenant($tenant, 'lectura');

    $this->actingAs($admin)->patchJson("/api/usuarios/{$objetivo->id}", [])->assertUnprocessable();
});
