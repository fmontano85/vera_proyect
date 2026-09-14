<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Route;
use Stancl\Tenancy\Database\Models\Tenant;

beforeEach(function () {
    Route::middleware(['auth:sanctum', 'tenant'])
        ->get('/_test/tenant-actual', fn () => response()->json([
            'tenant_id' => tenant('id'),
        ]));
});

it('inicializa el tenant a partir del tenant_id del usuario autenticado', function () {
    $tenant = Tenant::create();
    $user = User::factory()->create();
    $user->forceFill(['tenant_id' => $tenant->id])->save();

    $this->actingAs($user)
        ->getJson('/_test/tenant-actual')
        ->assertOk()
        ->assertJson(['tenant_id' => $tenant->id]);
});

it('rechaza el acceso si el usuario no tiene tenant asignado', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->getJson('/_test/tenant-actual')
        ->assertForbidden();
});

it('rechaza el acceso si el tenant del usuario ya no existe', function () {
    $tenant = Tenant::create();
    $user = User::factory()->create();
    $user->forceFill(['tenant_id' => $tenant->id])->save();
    $tenant->delete();

    $this->actingAs($user)
        ->getJson('/_test/tenant-actual')
        ->assertForbidden();
});

it('rechaza el acceso sin autenticacion', function () {
    $this->getJson('/_test/tenant-actual')
        ->assertUnauthorized();
});

it('no filtra el tenant de un usuario hacia el tenant de otro', function () {
    $tenantA = Tenant::create();
    $tenantB = Tenant::create();

    $userA = User::factory()->create();
    $userA->forceFill(['tenant_id' => $tenantA->id])->save();

    $userB = User::factory()->create();
    $userB->forceFill(['tenant_id' => $tenantB->id])->save();

    $responseA = $this->actingAs($userA)->getJson('/_test/tenant-actual');
    $responseB = $this->actingAs($userB)->getJson('/_test/tenant-actual');

    expect($responseA->json('tenant_id'))->toBe($tenantA->id);
    expect($responseB->json('tenant_id'))->toBe($tenantB->id);
    expect($responseA->json('tenant_id'))->not->toBe($responseB->json('tenant_id'));
});
