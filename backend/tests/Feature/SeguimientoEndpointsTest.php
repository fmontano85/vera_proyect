<?php

declare(strict_types=1);

use App\Models\FrecuenciaSeguimiento;
use App\Models\SearchResult;
use App\Models\Subject;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\RoleSeeder;
use Spatie\Activitylog\Models\Activity;
use Stancl\Tenancy\Database\Models\Tenant;

/**
 * Seccion 3.8 del CLAUDE.md raiz (Fase 2). Permisos confirmados:
 * marcar seguimiento y editar nivel/frecuencia del subject: admin,
 * oficial_cumplimiento, analista. Configurar defaults del tenant: solo
 * admin. lectura: solo ver. superadmin: 403 (regla existente).
 */
function crearUsuarioSeguimientoConRol(Tenant $tenant, string $rol): User
{
    $user = User::factory()->create();
    $user->forceFill(['tenant_id' => $tenant->id])->save();
    $user->assignRole($rol);

    return $user;
}

function crearSubjectEn(Tenant $tenant, array $atributos = []): Subject
{
    tenancy()->initialize($tenant);
    $subject = Subject::factory()->create($atributos);
    tenancy()->end();

    return $subject;
}

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    Carbon::setTestNow('2026-09-25 15:00:00');
});

afterEach(function () {
    Carbon::setTestNow();
});

it('analista marca seguimiento realizado: registra quien y cuando, recalcula la proxima fecha y audita la observacion', function () {
    $tenant = Tenant::create();
    $user = crearUsuarioSeguimientoConRol($tenant, 'analista');
    $subject = crearSubjectEn($tenant, ['nivel_riesgo' => 'alto']);

    $this->actingAs($user)
        ->postJson("/api/subjects/{$subject->id}/seguimiento-realizado", ['observacion' => 'Sin hallazgos nuevos.'])
        ->assertOk()
        ->assertJsonPath('seguimiento.proximo_seguimiento_en', '2026-10-25')
        ->assertJsonPath('seguimiento.frecuencia_dias', 30)
        ->assertJsonPath('seguimiento.origen_frecuencia', 'nivel');

    tenancy()->initialize($tenant);
    $subject->refresh();
    expect($subject->ultimo_seguimiento_por)->toBe($user->id)
        ->and($subject->ultimo_seguimiento_en->toDateTimeString())->toBe('2026-09-25 15:00:00');

    $actividad = Activity::where('subject_type', Subject::class)
        ->where('subject_id', $subject->id)
        ->where('event', 'seguimiento_realizado')
        ->sole();
    expect($actividad->causer_id)->toBe($user->id)
        ->and($actividad->properties['observacion'])->toBe('Sin hallazgos nuevos.');
    tenancy()->end();
});

it('lectura no puede marcar seguimiento realizado', function () {
    $tenant = Tenant::create();
    $user = crearUsuarioSeguimientoConRol($tenant, 'lectura');
    $subject = crearSubjectEn($tenant);

    $this->actingAs($user)
        ->postJson("/api/subjects/{$subject->id}/seguimiento-realizado")
        ->assertForbidden();
});

it('no se puede marcar seguimiento de un subject de otro tenant', function () {
    $user = crearUsuarioSeguimientoConRol(Tenant::create(), 'oficial_cumplimiento');
    $ajeno = crearSubjectEn(Tenant::create());

    $this->actingAs($user)
        ->postJson("/api/subjects/{$ajeno->id}/seguimiento-realizado")
        ->assertNotFound();
});

it('PATCH de subject cambia nivel y frecuencia personalizada, recalcula y queda auditado', function () {
    $tenant = Tenant::create();
    $user = crearUsuarioSeguimientoConRol($tenant, 'oficial_cumplimiento');
    $subject = crearSubjectEn($tenant, ['nivel_riesgo' => 'bajo']);

    $this->actingAs($user)
        ->patchJson("/api/subjects/{$subject->id}", ['nivel_riesgo' => 'medio', 'frecuencia_seguimiento_dias' => 15])
        ->assertOk()
        ->assertJsonPath('nivel_riesgo', 'medio')
        ->assertJsonPath('seguimiento.frecuencia_dias', 15)
        ->assertJsonPath('seguimiento.origen_frecuencia', 'personalizada')
        ->assertJsonPath('seguimiento.proximo_seguimiento_en', '2026-10-10');

    tenancy()->initialize($tenant);
    $cambio = Activity::where('subject_type', Subject::class)
        ->where('subject_id', $subject->id)
        ->where('event', 'updated')
        ->latest('id')
        ->first();
    expect($cambio->attribute_changes['attributes'])->toMatchArray(['nivel_riesgo' => 'medio', 'frecuencia_seguimiento_dias' => 15]);
    tenancy()->end();
});

it('PATCH con frecuencia null vuelve al default del nivel', function () {
    $tenant = Tenant::create();
    $user = crearUsuarioSeguimientoConRol($tenant, 'analista');
    $subject = crearSubjectEn($tenant, ['nivel_riesgo' => 'alto', 'frecuencia_seguimiento_dias' => 5]);

    $this->actingAs($user)
        ->patchJson("/api/subjects/{$subject->id}", ['frecuencia_seguimiento_dias' => null])
        ->assertOk()
        ->assertJsonPath('seguimiento.origen_frecuencia', 'nivel')
        ->assertJsonPath('seguimiento.frecuencia_dias', 30);
});

it('PATCH rechaza una frecuencia fuera de 1..365', function () {
    $tenant = Tenant::create();
    $user = crearUsuarioSeguimientoConRol($tenant, 'analista');
    $subject = crearSubjectEn($tenant);

    $this->actingAs($user)
        ->patchJson("/api/subjects/{$subject->id}", ['frecuencia_seguimiento_dias' => 0])
        ->assertUnprocessable();
});

it('lectura no puede editar un subject', function () {
    $tenant = Tenant::create();
    $user = crearUsuarioSeguimientoConRol($tenant, 'lectura');
    $subject = crearSubjectEn($tenant);

    $this->actingAs($user)
        ->patchJson("/api/subjects/{$subject->id}", ['nivel_riesgo' => 'alto'])
        ->assertForbidden();
});

it('GET de subject incluye el bloque de seguimiento', function () {
    $tenant = Tenant::create();
    $user = crearUsuarioSeguimientoConRol($tenant, 'lectura');
    $subject = crearSubjectEn($tenant, ['nivel_riesgo' => 'medio']);

    $this->actingAs($user)
        ->getJson("/api/subjects/{$subject->id}")
        ->assertOk()
        ->assertJsonPath('seguimiento.frecuencia_dias', 90)
        ->assertJsonPath('seguimiento.proximo_seguimiento_en', '2026-12-24')
        ->assertJsonPath('seguimiento.vencido', false);
});

it('el panel lista vencidos ordenados por fecha y luego por nivel de riesgo, solo activos y solo del tenant', function () {
    $tenant = Tenant::create();
    $user = crearUsuarioSeguimientoConRol($tenant, 'lectura');

    $bajoViejo = crearSubjectEn($tenant, ['nivel_riesgo' => 'bajo']);
    $altoMismoDia = crearSubjectEn($tenant, ['nivel_riesgo' => 'alto']);
    $medioAntes = crearSubjectEn($tenant, ['nivel_riesgo' => 'medio']);
    $noVencido = crearSubjectEn($tenant, ['nivel_riesgo' => 'alto']);
    $inactivo = crearSubjectEn($tenant, ['nivel_riesgo' => 'alto', 'activo' => false]);
    $otroTenant = crearSubjectEn(Tenant::create(), ['nivel_riesgo' => 'alto']);

    Subject::withoutGlobalScopes()->whereKey([$bajoViejo->id, $altoMismoDia->id, $inactivo->id, $otroTenant->id])
        ->update(['proximo_seguimiento_en' => '2026-09-20']);
    Subject::withoutGlobalScopes()->whereKey($medioAntes->id)->update(['proximo_seguimiento_en' => '2026-09-10']);

    $this->actingAs($user)
        ->getJson('/api/seguimientos?filtro=vencidos')
        ->assertOk()
        ->assertJsonPath('data.*.id', [$medioAntes->id, $altoMismoDia->id, $bajoViejo->id]);
});

it('el panel lista proximos (vencen dentro de 30 dias) sin incluir vencidos', function () {
    $tenant = Tenant::create();
    $user = crearUsuarioSeguimientoConRol($tenant, 'lectura');
    $pronto = crearSubjectEn($tenant, ['nivel_riesgo' => 'alto']); // 2026-10-25
    $lejano = crearSubjectEn($tenant, ['nivel_riesgo' => 'bajo']); // 2027-03-24
    $vencido = crearSubjectEn($tenant, ['nivel_riesgo' => 'alto']);
    Subject::withoutGlobalScopes()->whereKey($pronto->id)->update(['proximo_seguimiento_en' => '2026-10-05']);
    Subject::withoutGlobalScopes()->whereKey($vencido->id)->update(['proximo_seguimiento_en' => '2026-09-01']);

    $this->actingAs($user)
        ->getJson('/api/seguimientos?filtro=proximos')
        ->assertOk()
        ->assertJsonPath('data.*.id', [$pronto->id]);
});

it('cualquier rol del tenant ve la configuracion de frecuencias', function () {
    $tenant = Tenant::create();
    $user = crearUsuarioSeguimientoConRol($tenant, 'lectura');

    $this->actingAs($user)
        ->getJson('/api/configuracion/frecuencias-seguimiento')
        ->assertOk()
        ->assertJsonPath('alto', 30)
        ->assertJsonPath('sin_nivel', 180);
});

it('solo admin cambia la configuracion de frecuencias; recalcula subjects sin frecuencia propia y queda auditado', function () {
    $tenant = Tenant::create();
    $admin = crearUsuarioSeguimientoConRol($tenant, 'admin');
    $oficial = crearUsuarioSeguimientoConRol($tenant, 'oficial_cumplimiento');
    $porNivel = crearSubjectEn($tenant, ['nivel_riesgo' => 'alto']);
    $personalizado = crearSubjectEn($tenant, ['nivel_riesgo' => 'alto', 'frecuencia_seguimiento_dias' => 7]);

    $nuevas = ['alto' => 15, 'medio' => 60, 'bajo' => 120, 'sin_nivel' => 120];

    $this->actingAs($oficial)
        ->putJson('/api/configuracion/frecuencias-seguimiento', $nuevas)
        ->assertForbidden();

    $this->actingAs($admin)
        ->putJson('/api/configuracion/frecuencias-seguimiento', $nuevas)
        ->assertOk()
        ->assertJsonPath('alto', 15);

    tenancy()->initialize($tenant);
    expect($porNivel->fresh()->proximo_seguimiento_en->toDateString())->toBe('2026-10-10')
        ->and($personalizado->fresh()->proximo_seguimiento_en->toDateString())->toBe('2026-10-02')
        ->and(Activity::where('subject_type', FrecuenciaSeguimiento::class)->where('event', 'updated')->count())->toBe(4);
    tenancy()->end();
});

it('la configuracion exige los 4 niveles con dias entre 1 y 365', function () {
    $admin = crearUsuarioSeguimientoConRol(Tenant::create(), 'admin');

    $this->actingAs($admin)
        ->putJson('/api/configuracion/frecuencias-seguimiento', ['alto' => 0, 'medio' => 60, 'bajo' => 400])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['alto', 'bajo', 'sin_nivel']);
});

it('marca los resultados creados despues del ultimo seguimiento como nuevos desde el ultimo seguimiento', function () {
    $tenant = Tenant::create();
    $user = crearUsuarioSeguimientoConRol($tenant, 'lectura');
    $subject = crearSubjectEn($tenant);

    tenancy()->initialize($tenant);
    Carbon::setTestNow('2026-09-10 10:00:00');
    $viejo = SearchResult::factory()->for($subject, 'subject')->create();
    $subject->forceFill(['ultimo_seguimiento_en' => Carbon::parse('2026-09-15 10:00:00')])->save();
    Carbon::setTestNow('2026-09-20 10:00:00');
    $nuevo = SearchResult::factory()->for($subject, 'subject')->create();
    tenancy()->end();

    $respuesta = $this->actingAs($user)->getJson("/api/subjects/{$subject->id}/resultados")->assertOk();

    $porId = collect($respuesta->json('data'))->keyBy('id');
    expect($porId[$nuevo->id]['nuevo_desde_ultimo_seguimiento'])->toBeTrue()
        ->and($porId[$viejo->id]['nuevo_desde_ultimo_seguimiento'])->toBeFalse();
});

it('superadmin recibe 403 en las rutas de seguimiento', function () {
    $user = User::factory()->create();
    $user->assignRole('superadmin');

    $this->actingAs($user)->getJson('/api/seguimientos')->assertForbidden();
    $this->actingAs($user)->getJson('/api/configuracion/frecuencias-seguimiento')->assertForbidden();
});
