<?php

declare(strict_types=1);

use App\Models\Subject;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Stancl\Tenancy\Database\Models\Tenant;

function createUserWithRole(Tenant $tenant, string $role): User
{
    $user = User::factory()->create();
    $user->forceFill(['tenant_id' => $tenant->id])->save();
    $user->assignRole($role);

    return $user;
}

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

it('lectura no puede crear subjects', function () {
    $tenant = Tenant::create();
    $user = createUserWithRole($tenant, 'lectura');

    $this->actingAs($user)->postJson('/api/subjects', [
        'tipo' => 'natural',
        'nombre_canonico' => 'Juan Perez',
    ])->assertForbidden();
});

it('analista si puede crear subjects', function () {
    $tenant = Tenant::create();
    $user = createUserWithRole($tenant, 'analista');

    $this->actingAs($user)->postJson('/api/subjects', [
        'tipo' => 'natural',
        'nombre_canonico' => 'Juan Perez',
    ])->assertCreated();
});

it('oficial_cumplimiento si puede crear subjects', function () {
    $tenant = Tenant::create();
    $user = createUserWithRole($tenant, 'oficial_cumplimiento');

    $this->actingAs($user)->postJson('/api/subjects', [
        'tipo' => 'natural',
        'nombre_canonico' => 'Juan Perez',
    ])->assertCreated();
});

it('lectura si puede listar y ver subjects', function () {
    $tenant = Tenant::create();
    $user = createUserWithRole($tenant, 'lectura');

    tenancy()->initialize($tenant);
    $subject = Subject::factory()->create();
    tenancy()->end();

    $this->actingAs($user)->getJson('/api/subjects')->assertOk();
    $this->actingAs($user)->getJson("/api/subjects/{$subject->id}")->assertOk();
});

it('superadmin no puede ver subjects de ningun tenant (no son datos de su alcance)', function () {
    $tenantA = Tenant::create();
    $tenantB = Tenant::create();

    tenancy()->initialize($tenantA);
    Subject::factory()->create();
    tenancy()->end();

    tenancy()->initialize($tenantB);
    $subjectB = Subject::factory()->create();
    tenancy()->end();

    $superadmin = User::factory()->create();
    $superadmin->assignRole('superadmin');

    $this->actingAs($superadmin)->getJson('/api/subjects')->assertForbidden();
    $this->actingAs($superadmin)->getJson("/api/subjects/{$subjectB->id}")->assertForbidden();
});
