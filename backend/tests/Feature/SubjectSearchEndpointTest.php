<?php

declare(strict_types=1);

use App\Jobs\RunSubjectSearchJob;
use App\Models\MentionMatch;
use App\Models\Source;
use App\Models\Subject;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Bus;
use Stancl\Tenancy\Database\Models\Tenant;

function crearUsuarioConRol(Tenant $tenant, string $rol): User
{
    $user = User::factory()->create();
    $user->forceFill(['tenant_id' => $tenant->id])->save();
    $user->assignRole($rol);

    return $user;
}

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

it('analista puede iniciar una consulta puntual y encola RunSubjectSearchJob por cada source cse activa', function () {
    Bus::fake();

    $tenant = Tenant::create();
    $user = crearUsuarioConRol($tenant, 'analista');

    tenancy()->initialize($tenant);
    $subject = Subject::factory()->create();
    tenancy()->end();

    $cse = Source::factory()->create(['tipo' => 'cse', 'activo' => true]);
    Source::factory()->create(['tipo' => 'rss', 'activo' => true]);
    Source::factory()->create(['tipo' => 'cse', 'activo' => false]);

    $this->actingAs($user)
        ->postJson("/api/subjects/{$subject->id}/buscar")
        ->assertStatus(202)
        ->assertJson(['fuentes' => [$cse->id]]);

    Bus::assertDispatched(RunSubjectSearchJob::class, 1);
});

it('lectura no puede iniciar una consulta puntual', function () {
    $tenant = Tenant::create();
    $user = crearUsuarioConRol($tenant, 'lectura');

    tenancy()->initialize($tenant);
    $subject = Subject::factory()->create();
    tenancy()->end();

    Source::factory()->create(['tipo' => 'cse', 'activo' => true]);

    $this->actingAs($user)
        ->postJson("/api/subjects/{$subject->id}/buscar")
        ->assertForbidden();
});

it('responde 422 si no hay fuentes cse activas configuradas', function () {
    $tenant = Tenant::create();
    $user = crearUsuarioConRol($tenant, 'analista');

    tenancy()->initialize($tenant);
    $subject = Subject::factory()->create();
    tenancy()->end();

    $this->actingAs($user)
        ->postJson("/api/subjects/{$subject->id}/buscar")
        ->assertStatus(422);
});

it('lectura puede ver las coincidencias propuestas para un subject', function () {
    $tenant = Tenant::create();
    $user = crearUsuarioConRol($tenant, 'lectura');

    tenancy()->initialize($tenant);
    $subject = Subject::factory()->create();
    $match = MentionMatch::factory()->for($subject, 'subject')->create();
    tenancy()->end();

    $this->actingAs($user)
        ->getJson("/api/subjects/{$subject->id}/matches")
        ->assertOk()
        ->assertJsonPath('data.0.id', $match->id);
});
