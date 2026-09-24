<?php

declare(strict_types=1);

use App\Jobs\RunTagSearchJob;
use App\Models\SearchResult;
use App\Models\SearchTag;
use App\Models\Source;
use App\Models\Subject;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Bus;
use Stancl\Tenancy\Database\Models\Tenant;

function crearUsuarioBusquedaTagsConRol(Tenant $tenant, string $rol): User
{
    $user = User::factory()->create();
    $user->forceFill(['tenant_id' => $tenant->id])->save();
    $user->assignRole($rol);

    return $user;
}

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    Source::factory()->create(['tipo' => 'brave', 'activo' => true]);
});

it('analista puede disparar una busqueda por tags validos de su tenant', function () {
    Bus::fake();
    $tenant = Tenant::create();
    $user = crearUsuarioBusquedaTagsConRol($tenant, 'analista');

    tenancy()->initialize($tenant);
    $tagIds = SearchTag::whereIn('nombre', ['hurto', 'estafa'])->pluck('id');
    tenancy()->end();

    $this->actingAs($user)
        ->postJson('/api/busquedas-tags', ['tag_ids' => $tagIds->all()])
        ->assertStatus(202);

    Bus::assertDispatched(RunTagSearchJob::class);
});

it('lectura no puede disparar una busqueda por tags', function () {
    $tenant = Tenant::create();
    $user = crearUsuarioBusquedaTagsConRol($tenant, 'lectura');

    tenancy()->initialize($tenant);
    $tagIds = SearchTag::pluck('id');
    tenancy()->end();

    $this->actingAs($user)
        ->postJson('/api/busquedas-tags', ['tag_ids' => $tagIds->take(1)->all()])
        ->assertForbidden();
});

it('responde 422 si algun tag no pertenece al catalogo del tenant o esta inactivo', function () {
    $tenant = Tenant::create();
    $user = crearUsuarioBusquedaTagsConRol($tenant, 'analista');

    $otroTenant = Tenant::create();
    tenancy()->initialize($otroTenant);
    $tagDeOtroTenant = SearchTag::first();
    tenancy()->end();

    $this->actingAs($user)
        ->postJson('/api/busquedas-tags', ['tag_ids' => [$tagDeOtroTenant->id]])
        ->assertStatus(422);
});

it('responde 422 si un tag esta inactivo', function () {
    $tenant = Tenant::create();
    $user = crearUsuarioBusquedaTagsConRol($tenant, 'analista');

    tenancy()->initialize($tenant);
    $tag = SearchTag::first();
    $tag->forceFill(['activo' => false])->save();
    tenancy()->end();

    $this->actingAs($user)
        ->postJson('/api/busquedas-tags', ['tag_ids' => [$tag->id]])
        ->assertStatus(422);
});

it('lista los resultados sin subject de las busquedas por tags del tenant', function () {
    $tenant = Tenant::create();
    $user = crearUsuarioBusquedaTagsConRol($tenant, 'analista');

    tenancy()->initialize($tenant);
    $subject = Subject::factory()->create();
    $searchRun = \App\Models\SearchRun::factory()->create(['subject_id' => null, 'tags' => ['hurto']]);
    SearchResult::factory()->create(['subject_id' => null, 'search_run_id' => $searchRun->id]);
    // Uno de un subject normal no deberia aparecer aqui.
    SearchResult::factory()->for($subject, 'subject')->create();
    tenancy()->end();

    $this->actingAs($user)
        ->getJson('/api/busquedas-tags/resultados')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

it('superadmin recibe 403 en las rutas de busqueda por tags', function () {
    $superadmin = User::factory()->create();
    $superadmin->assignRole('superadmin');

    $this->actingAs($superadmin)->postJson('/api/busquedas-tags', ['tag_ids' => [1]])->assertForbidden();
    $this->actingAs($superadmin)->getJson('/api/busquedas-tags/resultados')->assertForbidden();
});
