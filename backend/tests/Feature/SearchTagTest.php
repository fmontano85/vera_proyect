<?php

declare(strict_types=1);

use App\Models\SearchTag;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Stancl\Tenancy\Database\Models\Tenant;

function crearUsuarioTagConRol(Tenant $tenant, string $rol): User
{
    $user = User::factory()->create();
    $user->forceFill(['tenant_id' => $tenant->id])->save();
    $user->assignRole($rol);

    return $user;
}

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

it('siembra el catalogo de tags por defecto al crear un tenant nuevo', function () {
    $tenant = Tenant::create();

    tenancy()->initialize($tenant);
    $nombres = SearchTag::pluck('nombre')->sort()->values()->all();
    tenancy()->end();

    expect($nombres)->toBe([
        'captura',
        'condena',
        'corrupcion',
        'estafa',
        'extorsion',
        'hurto',
        'lavado de dinero',
        'narcotrafico',
    ]);
});

it('cada tenant tiene su propio catalogo, aislado del de otro tenant', function () {
    $tenantA = Tenant::create();
    $tenantB = Tenant::create();

    tenancy()->initialize($tenantA);
    SearchTag::create(['nombre' => 'delito exclusivo de A', 'activo' => true]);
    tenancy()->end();

    tenancy()->initialize($tenantB);
    expect(SearchTag::where('nombre', 'delito exclusivo de A')->exists())->toBeFalse();
    tenancy()->end();
});

it('analista puede listar y agregar tags al catalogo de su tenant', function () {
    $tenant = Tenant::create();
    $user = crearUsuarioTagConRol($tenant, 'analista');

    $this->actingAs($user)->getJson('/api/tags-busqueda')->assertOk()->assertJsonCount(8);

    $this->actingAs($user)
        ->postJson('/api/tags-busqueda', ['nombre' => 'trata de personas'])
        ->assertCreated()
        ->assertJsonPath('nombre', 'trata de personas');

    $this->actingAs($user)->getJson('/api/tags-busqueda')->assertJsonCount(9);
});

it('lectura no puede agregar tags', function () {
    $tenant = Tenant::create();
    $user = crearUsuarioTagConRol($tenant, 'lectura');

    $this->actingAs($user)
        ->postJson('/api/tags-busqueda', ['nombre' => 'nuevo tag'])
        ->assertForbidden();
});

it('responde 422 si el tag ya existe en ese tenant', function () {
    $tenant = Tenant::create();
    $user = crearUsuarioTagConRol($tenant, 'analista');

    $this->actingAs($user)->postJson('/api/tags-busqueda', ['nombre' => 'hurto'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('nombre');
});

it('el mismo nombre de tag es valido en tenants distintos', function () {
    $tenantA = Tenant::create();
    $tenantB = Tenant::create();
    $userA = crearUsuarioTagConRol($tenantA, 'analista');
    $userB = crearUsuarioTagConRol($tenantB, 'analista');

    $this->actingAs($userA)->postJson('/api/tags-busqueda', ['nombre' => 'delito compartido'])
        ->assertCreated();

    $this->actingAs($userB)->postJson('/api/tags-busqueda', ['nombre' => 'delito compartido'])
        ->assertCreated();
});
