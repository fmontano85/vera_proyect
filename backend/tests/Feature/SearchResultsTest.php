<?php

declare(strict_types=1);

use App\Enums\EstadoSearchResult;
use App\Jobs\FetchArticleJob;
use App\Models\SearchResult;
use App\Models\Subject;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Bus;
use Stancl\Tenancy\Database\Models\Tenant;

function crearUsuarioResultadoConRol(Tenant $tenant, string $rol): User
{
    $user = User::factory()->create();
    $user->forceFill(['tenant_id' => $tenant->id])->save();
    $user->assignRole($rol);

    return $user;
}

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

it('lectura puede listar los resultados de un subject con sus menciones y matches anidados', function () {
    $tenant = Tenant::create();
    $user = crearUsuarioResultadoConRol($tenant, 'lectura');

    tenancy()->initialize($tenant);
    $subject = Subject::factory()->create();
    SearchResult::factory()->for($subject, 'subject')->create();
    tenancy()->end();

    $this->actingAs($user)
        ->getJson("/api/subjects/{$subject->id}/resultados")
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

it('analista puede pedir extraer un resultado nuevo y encola FetchArticleJob', function () {
    Bus::fake();
    $tenant = Tenant::create();
    $user = crearUsuarioResultadoConRol($tenant, 'analista');

    tenancy()->initialize($tenant);
    $subject = Subject::factory()->create();
    $resultado = SearchResult::factory()->for($subject, 'subject')->create();
    tenancy()->end();

    $this->actingAs($user)
        ->postJson("/api/resultados/{$resultado->id}/extraer")
        ->assertOk()
        ->assertJsonPath('estado', 'procesando');

    Bus::assertDispatched(FetchArticleJob::class);
});

it('lectura no puede pedir extraer un resultado', function () {
    $tenant = Tenant::create();
    $user = crearUsuarioResultadoConRol($tenant, 'lectura');

    tenancy()->initialize($tenant);
    $subject = Subject::factory()->create();
    $resultado = SearchResult::factory()->for($subject, 'subject')->create();
    tenancy()->end();

    $this->actingAs($user)
        ->postJson("/api/resultados/{$resultado->id}/extraer")
        ->assertForbidden();
});

it('responde 422 al pedir extraer un resultado que no esta en nuevo ni en gap', function () {
    $tenant = Tenant::create();
    $user = crearUsuarioResultadoConRol($tenant, 'analista');

    tenancy()->initialize($tenant);
    $subject = Subject::factory()->create();
    $resultado = SearchResult::factory()->for($subject, 'subject')->create();
    $resultado->forceFill(['estado' => EstadoSearchResult::Extraido])->save();
    tenancy()->end();

    $this->actingAs($user)
        ->postJson("/api/resultados/{$resultado->id}/extraer")
        ->assertStatus(422);
});

it('permite reintentar extraer un resultado en gap', function () {
    Bus::fake();
    $tenant = Tenant::create();
    $user = crearUsuarioResultadoConRol($tenant, 'analista');

    tenancy()->initialize($tenant);
    $subject = Subject::factory()->create();
    $resultado = SearchResult::factory()->for($subject, 'subject')->create();
    $resultado->forceFill(['estado' => EstadoSearchResult::Gap, 'gap_motivo' => \App\Enums\GapMotivo::Timeout])->save();
    tenancy()->end();

    $this->actingAs($user)
        ->postJson("/api/resultados/{$resultado->id}/extraer")
        ->assertOk()
        ->assertJsonPath('estado', 'procesando')
        ->assertJsonPath('gap_motivo', null);
});

it('analista puede descartar un resultado y queda auditado', function () {
    $tenant = Tenant::create();
    $user = crearUsuarioResultadoConRol($tenant, 'analista');

    tenancy()->initialize($tenant);
    $subject = Subject::factory()->create();
    $resultado = SearchResult::factory()->for($subject, 'subject')->create();
    tenancy()->end();

    $this->actingAs($user)
        ->postJson("/api/resultados/{$resultado->id}/descartar")
        ->assertOk()
        ->assertJsonPath('estado', 'descartado');

    tenancy()->initialize($tenant);
    expect($resultado->refresh()->descartado_por)->toBe($user->id);
    tenancy()->end();
});

it('responde 422 al descartar un resultado ya descartado', function () {
    $tenant = Tenant::create();
    $user = crearUsuarioResultadoConRol($tenant, 'analista');

    tenancy()->initialize($tenant);
    $subject = Subject::factory()->create();
    $resultado = SearchResult::factory()->for($subject, 'subject')->create();
    $resultado->forceFill(['estado' => EstadoSearchResult::Descartado])->save();
    tenancy()->end();

    $this->actingAs($user)
        ->postJson("/api/resultados/{$resultado->id}/descartar")
        ->assertStatus(422);
});

it('superadmin recibe 403 en las rutas de resultados', function () {
    $tenant = Tenant::create();
    tenancy()->initialize($tenant);
    $subject = Subject::factory()->create();
    $resultado = SearchResult::factory()->for($subject, 'subject')->create();
    tenancy()->end();

    $superadmin = User::factory()->create();
    $superadmin->assignRole('superadmin');

    $this->actingAs($superadmin)
        ->getJson("/api/subjects/{$subject->id}/resultados")
        ->assertForbidden();
    $this->actingAs($superadmin)
        ->postJson("/api/resultados/{$resultado->id}/extraer")
        ->assertForbidden();
});
