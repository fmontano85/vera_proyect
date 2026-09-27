<?php

declare(strict_types=1);

use App\Models\Subject;
use App\Models\SubjectAlias;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Stancl\Tenancy\Database\Models\Tenant;

function usuarioHistorial(Tenant $tenant, string $rol): User
{
    $user = User::factory()->create(['name' => 'Ana Oficial']);
    $user->forceFill(['tenant_id' => $tenant->id])->save();
    $user->assignRole($rol);

    return $user;
}

beforeEach(fn () => $this->seed(RoleSeeder::class));

it('lista los cambios del sujeto, sus aliases (incluso borrados) y su seguimiento, del mas reciente al mas antiguo', function () {
    $tenant = Tenant::create();
    $user = usuarioHistorial($tenant, 'lectura');

    tenancy()->initialize($tenant);
    $subject = Subject::factory()->create(['nivel_riesgo' => 'bajo']);
    $subject->update(['nivel_riesgo' => 'alto']);
    $alias = SubjectAlias::factory()->for($subject, 'subject')->create(['nombre' => 'El Chele']);
    $alias->delete();
    activity()->performedOn($subject)->causedBy($user)->event('seguimiento_realizado')
        ->withProperties(['observacion' => 'Sin hallazgos.'])->log('Seguimiento realizado');
    tenancy()->end();

    $respuesta = $this->actingAs($user)->getJson("/api/subjects/{$subject->id}/historial")->assertOk();

    $eventos = collect($respuesta->json('data'));
    expect($eventos->pluck('entidad')->unique()->sort()->values()->all())->toBe(['alias', 'subject']);
    expect($eventos->pluck('evento')->all())->toContain('created', 'updated', 'deleted', 'seguimiento_realizado');
    expect($eventos->first()['evento'])->toBe('seguimiento_realizado');
    expect($eventos->first()['usuario'])->toBe('Ana Oficial');
    expect($eventos->first()['propiedades']['observacion'])->toBe('Sin hallazgos.');
});

it('no mezcla la actividad de otro sujeto del mismo tenant', function () {
    $tenant = Tenant::create();
    $user = usuarioHistorial($tenant, 'analista');

    tenancy()->initialize($tenant);
    $a = Subject::factory()->create();
    $b = Subject::factory()->create();
    $b->update(['nivel_riesgo' => 'alto']);
    SubjectAlias::factory()->for($b, 'subject')->create();
    tenancy()->end();

    $eventos = collect($this->actingAs($user)->getJson("/api/subjects/{$a->id}/historial")->json('data'));

    expect($eventos)->toHaveCount(1)->and($eventos->first()['evento'])->toBe('created');
});

it('un usuario de otro tenant recibe 404', function () {
    $tenantA = Tenant::create();
    $tenantB = Tenant::create();
    $intruso = usuarioHistorial($tenantB, 'admin');

    tenancy()->initialize($tenantA);
    $subject = Subject::factory()->create();
    tenancy()->end();

    $this->actingAs($intruso)->getJson("/api/subjects/{$subject->id}/historial")->assertNotFound();
});

it('no expone correos ni datos del usuario, solo su nombre', function () {
    $tenant = Tenant::create();
    $user = usuarioHistorial($tenant, 'admin');

    tenancy()->initialize($tenant);
    $subject = Subject::factory()->create();
    activity()->performedOn($subject)->causedBy($user)->log('x');
    tenancy()->end();

    $cuerpo = $this->actingAs($user)->getJson("/api/subjects/{$subject->id}/historial")->getContent();

    expect($cuerpo)->not->toContain($user->email);
});

it('exige autenticacion', function () {
    $this->getJson('/api/subjects/1/historial')->assertUnauthorized();
});
