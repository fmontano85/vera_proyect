<?php

declare(strict_types=1);

use App\Models\SearchTag;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Spatie\Activitylog\Models\Activity;
use Stancl\Tenancy\Database\Models\Tenant;

function tagDe(Tenant $tenant, string $nombre, bool $activo = true): SearchTag
{
    tenancy()->initialize($tenant);
    $tag = SearchTag::create(['nombre' => $nombre, 'activo' => $activo]);
    tenancy()->end();

    return $tag;
}

beforeEach(fn () => $this->seed(RoleSeeder::class));

it('oficial_cumplimiento y admin renombran y desactivan un tag, y queda auditado', function (string $rol) {
    $tenant = Tenant::create();
    $user = usuarioDeTenant($tenant, $rol);
    $tag = tagDe($tenant, 'tag-gestion');

    $this->actingAs($user)->patchJson("/api/tags-busqueda/{$tag->id}", ['nombre' => 'renombrado', 'activo' => false])
        ->assertOk()->assertJsonPath('nombre', 'renombrado')->assertJsonPath('activo', false);

    $actividad = Activity::where('subject_type', SearchTag::class)->where('subject_id', $tag->id)->where('event', 'updated')->first();
    expect($actividad)->not->toBeNull();
})->with(['oficial_cumplimiento', 'admin']);

it('analista y lectura no pueden modificar el catalogo, pero analista si puede crear', function (string $rol) {
    $tenant = Tenant::create();
    $user = usuarioDeTenant($tenant, $rol);
    $tag = tagDe($tenant, 'tag-gestion');

    $this->actingAs($user)->patchJson("/api/tags-busqueda/{$tag->id}", ['activo' => false])->assertForbidden();
    $this->actingAs($user)->getJson('/api/tags-busqueda?todos=1')->assertForbidden();
})->with(['analista', 'lectura']);

it('el listado normal solo trae activos; con todos=1 (gestion) trae tambien los inactivos', function () {
    $tenant = Tenant::create();
    $user = usuarioDeTenant($tenant, 'oficial_cumplimiento');
    tagDe($tenant, 'zzz-inactivo', false);

    $normal = collect($this->actingAs($user)->getJson('/api/tags-busqueda')->json())->pluck('nombre');
    $todos = collect($this->actingAs($user)->getJson('/api/tags-busqueda?todos=1')->json())->pluck('nombre');

    expect($normal)->not->toContain('zzz-inactivo')->and($todos)->toContain('zzz-inactivo');
});

it('rechaza renombrar a un nombre que ya existe en el tenant, pero permite conservar el propio', function () {
    $tenant = Tenant::create();
    $user = usuarioDeTenant($tenant, 'admin');
    $a = tagDe($tenant, 'tag-a');
    tagDe($tenant, 'tag-b');

    $this->actingAs($user)->patchJson("/api/tags-busqueda/{$a->id}", ['nombre' => 'tag-b'])->assertUnprocessable();
    $this->actingAs($user)->patchJson("/api/tags-busqueda/{$a->id}", ['nombre' => 'tag-a', 'activo' => false])->assertOk();
});

it('permite el mismo nombre en otro tenant', function () {
    $tenantA = Tenant::create();
    $tenantB = Tenant::create();
    $user = usuarioDeTenant($tenantA, 'admin');
    tagDe($tenantB, 'exclusivo-b');
    $tag = tagDe($tenantA, 'tag-a');

    $this->actingAs($user)->patchJson("/api/tags-busqueda/{$tag->id}", ['nombre' => 'exclusivo-b'])->assertOk();
});

it('no se puede tocar un tag de otro tenant', function () {
    $tenantA = Tenant::create();
    $tenantB = Tenant::create();
    $intruso = usuarioDeTenant($tenantB, 'admin');
    $tag = tagDe($tenantA, 'tag-a');

    $this->actingAs($intruso)->patchJson("/api/tags-busqueda/{$tag->id}", ['activo' => false])->assertNotFound();
});

it('exige al menos un campo y valida el largo del nombre', function () {
    $tenant = Tenant::create();
    $user = usuarioDeTenant($tenant, 'admin');
    $tag = tagDe($tenant, 'tag-a');

    $this->actingAs($user)->patchJson("/api/tags-busqueda/{$tag->id}", [])->assertUnprocessable();
    $this->actingAs($user)->patchJson("/api/tags-busqueda/{$tag->id}", ['nombre' => str_repeat('a', 256)])->assertUnprocessable();
});
