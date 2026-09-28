<?php

declare(strict_types=1);

use App\Jobs\ActualizarListaOfacProgramadaJob;
use App\Jobs\ImportSanctionListsJob;
use App\Jobs\MatchSanctionsJob;
use App\Models\ConfiguracionSanciones;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Bus;
use Spatie\Activitylog\Models\Activity;
use Stancl\Tenancy\Database\Models\Tenant;

function superadmin(): User
{
    $user = User::factory()->create();
    $user->assignRole('superadmin');

    return $user;
}

beforeEach(fn () => $this->seed(RoleSeeder::class));

it('solo superadmin ve y gestiona el panel; el resto de roles recibe 403', function (string $rol) {
    $tenant = Tenant::create();
    $user = usuarioDeTenant($tenant, $rol);

    $this->actingAs($user)->getJson('/api/superadmin/tenants')->assertForbidden();
    $this->actingAs($user)->patchJson("/api/superadmin/tenants/{$tenant->id}", ['sanciones_habilitado' => true])->assertForbidden();
    $this->actingAs($user)->getJson('/api/superadmin/configuracion-sanciones')->assertForbidden();
    $this->actingAs($user)->putJson('/api/superadmin/configuracion-sanciones', ['modo_descarga_ofac' => 'manual'])->assertForbidden();
    $this->actingAs($user)->postJson('/api/superadmin/sanciones/actualizar-lista')->assertForbidden();
})->with(['admin', 'oficial_cumplimiento', 'analista', 'lectura']);

it('lista los tenants con nombre y estado de sanciones, sin datos de negocio', function () {
    $tenant = Tenant::create(['name' => 'Empresa Uno']);
    $super = superadmin();

    $respuesta = $this->actingAs($super)->getJson('/api/superadmin/tenants')->assertOk();

    expect($respuesta->json('0'))->toHaveKeys(['id', 'name', 'sanciones_habilitado'])
        ->and($respuesta->json('0.name'))->toBe('Empresa Uno')
        ->and($respuesta->json('0.sanciones_habilitado'))->toBeFalse();
});

it('activa y desactiva sanciones para un tenant especifico, y queda auditado', function () {
    $tenant = Tenant::create();
    $super = superadmin();

    $this->actingAs($super)->patchJson("/api/superadmin/tenants/{$tenant->id}", ['sanciones_habilitado' => true])
        ->assertOk()->assertJsonPath('sanciones_habilitado', true);

    expect((bool) $tenant->refresh()->sanciones_habilitado)->toBeTrue();
    expect(Activity::where('subject_type', Tenant::class)->where('subject_id', $tenant->id)->where('event', 'sanciones_cambiado')->exists())->toBeTrue();

    $this->actingAs($super)->patchJson("/api/superadmin/tenants/{$tenant->id}", ['sanciones_habilitado' => false])
        ->assertOk()->assertJsonPath('sanciones_habilitado', false);
    expect((bool) $tenant->refresh()->sanciones_habilitado)->toBeFalse();
});

it('activar sanciones en un tenant no afecta a los demas', function () {
    $a = Tenant::create();
    $b = Tenant::create();
    $super = superadmin();

    $this->actingAs($super)->patchJson("/api/superadmin/tenants/{$a->id}", ['sanciones_habilitado' => true])->assertOk();

    // Tenant (vendor, sin cast propio) devuelve el tinyint tal cual de MariaDB;
    // el serializador de la API si fuerza (bool) - ver serializarTenant().
    expect((bool) $a->refresh()->sanciones_habilitado)->toBeTrue()
        ->and((bool) $b->refresh()->sanciones_habilitado)->toBeFalse();
});

it('ve y cambia el modo de descarga global de OFAC, y queda auditado con quien', function () {
    $super = superadmin();

    $this->actingAs($super)->getJson('/api/superadmin/configuracion-sanciones')
        ->assertOk()->assertJsonPath('modo_descarga_ofac', 'automatico');

    $this->actingAs($super)->putJson('/api/superadmin/configuracion-sanciones', ['modo_descarga_ofac' => 'manual'])
        ->assertOk()->assertJsonPath('modo_descarga_ofac', 'manual');

    expect(ConfiguracionSanciones::modoDescarga())->toBe('manual');
    $config = ConfiguracionSanciones::actual();
    expect($config->actualizado_por)->toBe($super->id);
    expect(Activity::where('subject_type', ConfiguracionSanciones::class)->where('event', 'updated')->exists())->toBeTrue();
});

it('rechaza un modo de descarga invalido', function () {
    $super = superadmin();

    $this->actingAs($super)->putJson('/api/superadmin/configuracion-sanciones', ['modo_descarga_ofac' => 'otro'])
        ->assertUnprocessable();
});

it('el superadmin puede disparar la actualizacion de la lista manualmente en cualquier modo', function () {
    Bus::fake();
    $super = superadmin();

    $this->actingAs($super)->postJson('/api/superadmin/sanciones/actualizar-lista')->assertOk();

    Bus::assertChained([ImportSanctionListsJob::class, MatchSanctionsJob::class]);
});

it('el job semanal no dispara nada si el modo es manual', function () {
    Bus::fake();
    ConfiguracionSanciones::actual()->update(['modo_descarga_ofac' => 'manual']);

    (new ActualizarListaOfacProgramadaJob)->handle();

    Bus::assertNothingDispatched();
});

it('el job semanal dispara la importacion+cruce si el modo es automatico', function () {
    Bus::fake();
    ConfiguracionSanciones::actual()->update(['modo_descarga_ofac' => 'automatico']);

    (new ActualizarListaOfacProgramadaJob)->handle();

    Bus::assertChained([ImportSanctionListsJob::class, MatchSanctionsJob::class]);
});
