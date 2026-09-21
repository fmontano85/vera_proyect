<?php

declare(strict_types=1);

use App\Models\Subject;
use App\Models\SubjectAlias;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Stancl\Tenancy\Database\Models\Tenant;

function createUserForTenant(Tenant $tenant): User
{
    $user = User::factory()->create();
    $user->forceFill(['tenant_id' => $tenant->id])->save();

    return $user;
}

it('asigna el tenant del usuario autenticado al crear un subject', function () {
    $this->seed(RoleSeeder::class);
    $tenant = Tenant::create();
    $user = createUserForTenant($tenant);
    $user->assignRole('analista');

    $response = $this->actingAs($user)->postJson('/api/subjects', [
        'tipo' => 'natural',
        'nombre_canonico' => 'Juan Perez',
    ]);

    $response->assertCreated();

    tenancy()->initialize($tenant);
    expect(Subject::find($response->json('id'))->tenant_id)->toBe($tenant->id);
    tenancy()->end();
});

it('no lista subjects de otro tenant', function () {
    $tenantA = Tenant::create();
    $tenantB = Tenant::create();

    tenancy()->initialize($tenantB);
    Subject::factory()->create(['nombre_canonico' => 'De tenant B']);
    tenancy()->end();

    $userA = createUserForTenant($tenantA);

    $response = $this->actingAs($userA)->getJson('/api/subjects');

    $response->assertOk();
    expect($response->json('data'))->toBeEmpty();
});

it('responde 404 al pedir un subject de otro tenant', function () {
    $tenantA = Tenant::create();
    $tenantB = Tenant::create();

    tenancy()->initialize($tenantB);
    $subjectB = Subject::factory()->create();
    tenancy()->end();

    $userA = createUserForTenant($tenantA);

    $this->actingAs($userA)
        ->getJson("/api/subjects/{$subjectB->id}")
        ->assertNotFound();
});

it('subject_aliases hereda el aislamiento de tenant', function () {
    $tenantA = Tenant::create();
    $tenantB = Tenant::create();

    tenancy()->initialize($tenantA);
    $subjectA = Subject::factory()->create();
    SubjectAlias::factory()->for($subjectA, 'subject')->create();
    tenancy()->end();

    tenancy()->initialize($tenantB);
    expect(SubjectAlias::count())->toBe(0);
    tenancy()->end();

    tenancy()->initialize($tenantA);
    expect(SubjectAlias::count())->toBe(1);
    tenancy()->end();
});

it('un alias toma el tenant_id de su subject cuando el tenant ambiente coincide', function () {
    $tenantA = Tenant::create();

    tenancy()->initialize($tenantA);
    $subjectA = Subject::factory()->create();
    $alias = SubjectAlias::factory()->for($subjectA, 'subject')->create();
    tenancy()->end();

    expect($alias->tenant_id)->toBe($tenantA->id);
});

it('falla al crear un alias si el tenant ambiente no es el del subject', function () {
    $tenantA = Tenant::create();
    $tenantB = Tenant::create();

    tenancy()->initialize($tenantA);
    $subjectA = Subject::factory()->create();
    tenancy()->end();

    // subject_id de tenant A referenciado con tenant B inicializado por
    // error (o por un intento de referenciar un subject ajeno): debe
    // fallar cerrado, no "adivinar" y crear el alias igual bajo tenant A.
    tenancy()->initialize($tenantB);
    expect(fn () => SubjectAlias::factory()->for($subjectA, 'subject')->create())
        ->toThrow(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
    tenancy()->end();
});
