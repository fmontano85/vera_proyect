<?php

declare(strict_types=1);

use App\Models\MentionMatch;
use App\Models\Subject;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Spatie\Activitylog\Models\Activity;
use Stancl\Tenancy\Database\Models\Tenant;

function crearUsuarioMatchConRol(Tenant $tenant, string $rol): User
{
    $user = User::factory()->create();
    $user->forceFill(['tenant_id' => $tenant->id])->save();
    $user->assignRole($rol);

    return $user;
}

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

it('analista puede proponer una resolucion pero no cambia el estado final', function () {
    $tenant = Tenant::create();
    $user = crearUsuarioMatchConRol($tenant, 'analista');

    tenancy()->initialize($tenant);
    $subject = Subject::factory()->create();
    $match = MentionMatch::factory()->for($subject, 'subject')->create();
    tenancy()->end();

    $this->actingAs($user)
        ->postJson("/api/matches/{$match->id}/proponer", ['estado' => 'confirmado'])
        ->assertOk()
        ->assertJsonPath('propuesta_estado', 'confirmado')
        ->assertJsonPath('estado', 'pendiente');

    tenancy()->initialize($tenant);
    $match->refresh();
    expect($match->estado)->toBe('pendiente')
        ->and($match->propuesta_estado)->toBe('confirmado')
        ->and($match->propuesta_por)->toBe($user->id);
    tenancy()->end();
});

it('lectura no puede proponer una resolucion', function () {
    $tenant = Tenant::create();
    $user = crearUsuarioMatchConRol($tenant, 'lectura');

    tenancy()->initialize($tenant);
    $subject = Subject::factory()->create();
    $match = MentionMatch::factory()->for($subject, 'subject')->create();
    tenancy()->end();

    $this->actingAs($user)
        ->postJson("/api/matches/{$match->id}/proponer", ['estado' => 'confirmado'])
        ->assertForbidden();
});

it('analista no puede resolver (solo proponer)', function () {
    $tenant = Tenant::create();
    $user = crearUsuarioMatchConRol($tenant, 'analista');

    tenancy()->initialize($tenant);
    $subject = Subject::factory()->create();
    $match = MentionMatch::factory()->for($subject, 'subject')->create();
    tenancy()->end();

    $this->actingAs($user)
        ->postJson("/api/matches/{$match->id}/resolver", ['estado' => 'confirmado'])
        ->assertForbidden();
});

it('oficial_cumplimiento puede resolver directo, sin necesitar una propuesta previa', function () {
    $tenant = Tenant::create();
    $oficial = crearUsuarioMatchConRol($tenant, 'oficial_cumplimiento');

    tenancy()->initialize($tenant);
    $subject = Subject::factory()->create();
    $match = MentionMatch::factory()->for($subject, 'subject')->create();
    tenancy()->end();

    $this->actingAs($oficial)
        ->postJson("/api/matches/{$match->id}/resolver", ['estado' => 'falso_positivo'])
        ->assertOk()
        ->assertJsonPath('estado', 'falso_positivo');

    tenancy()->initialize($tenant);
    $match->refresh();
    expect($match->estado)->toBe('falso_positivo')
        ->and($match->resuelto_por)->toBe($oficial->id)
        ->and($match->resuelto_en)->not->toBeNull();
    tenancy()->end();
});

it('oficial_cumplimiento puede resolver distinto a lo que propuso el analista', function () {
    $tenant = Tenant::create();
    $analista = crearUsuarioMatchConRol($tenant, 'analista');
    $oficial = crearUsuarioMatchConRol($tenant, 'oficial_cumplimiento');

    tenancy()->initialize($tenant);
    $subject = Subject::factory()->create();
    $match = MentionMatch::factory()->for($subject, 'subject')->create();
    tenancy()->end();

    $this->actingAs($analista)->postJson("/api/matches/{$match->id}/proponer", ['estado' => 'confirmado']);

    $this->actingAs($oficial)
        ->postJson("/api/matches/{$match->id}/resolver", ['estado' => 'homonimo'])
        ->assertOk();

    tenancy()->initialize($tenant);
    $match->refresh();
    expect($match->propuesta_estado)->toBe('confirmado')
        ->and($match->estado)->toBe('homonimo');
    tenancy()->end();
});

it('responde 422 si se propone o resuelve sobre un match que ya no esta pendiente', function () {
    $tenant = Tenant::create();
    $oficial = crearUsuarioMatchConRol($tenant, 'oficial_cumplimiento');

    tenancy()->initialize($tenant);
    $subject = Subject::factory()->create();
    $match = MentionMatch::factory()->for($subject, 'subject')->create();
    $match->forceFill(['estado' => 'confirmado'])->save();
    tenancy()->end();

    $this->actingAs($oficial)
        ->postJson("/api/matches/{$match->id}/proponer", ['estado' => 'homonimo'])
        ->assertStatus(422);

    $this->actingAs($oficial)
        ->postJson("/api/matches/{$match->id}/resolver", ['estado' => 'homonimo'])
        ->assertStatus(422);
});

it('rechaza un estado invalido (ej. pendiente) en proponer y resolver', function () {
    $tenant = Tenant::create();
    $oficial = crearUsuarioMatchConRol($tenant, 'oficial_cumplimiento');

    tenancy()->initialize($tenant);
    $subject = Subject::factory()->create();
    $match = MentionMatch::factory()->for($subject, 'subject')->create();
    tenancy()->end();

    $this->actingAs($oficial)
        ->postJson("/api/matches/{$match->id}/resolver", ['estado' => 'pendiente'])
        ->assertStatus(422);
});

it('registra en activity_log la propuesta y la resolucion', function () {
    $tenant = Tenant::create();
    $analista = crearUsuarioMatchConRol($tenant, 'analista');
    $oficial = crearUsuarioMatchConRol($tenant, 'oficial_cumplimiento');

    tenancy()->initialize($tenant);
    $subject = Subject::factory()->create();
    $match = MentionMatch::factory()->for($subject, 'subject')->create();
    tenancy()->end();

    $this->actingAs($analista)->postJson("/api/matches/{$match->id}/proponer", ['estado' => 'confirmado']);
    $this->actingAs($oficial)->postJson("/api/matches/{$match->id}/resolver", ['estado' => 'confirmado']);

    tenancy()->initialize($tenant);
    expect(
        Activity::where('subject_type', MentionMatch::class)
            ->where('subject_id', $match->id)
            ->where('event', 'updated')
            ->count()
    )->toBe(2);
    tenancy()->end();
});
