<?php

declare(strict_types=1);

use App\Enums\EstadoSearchResult;
use App\Models\Article;
use App\Models\Mention;
use App\Models\MentionMatch;
use App\Models\SearchResult;
use App\Models\Subject;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\RoleSeeder;
use Stancl\Tenancy\Database\Models\Tenant;

/**
 * Dashboard de coincidencias pendientes (Fase 2, seccion 5) + inicio.
 * Bandejas: sin_propuesta (el analista debe proponer) y
 * esperan_resolucion (el oficial debe resolver, seccion 3.2).
 */
function usuarioDashboardConRol(Tenant $tenant, string $rol): User
{
    $user = User::factory()->create();
    $user->forceFill(['tenant_id' => $tenant->id])->save();
    $user->assignRole($rol);

    return $user;
}

/** @param array<string, mixed> $match */
function coincidenciaEn(Tenant $tenant, array $match = [], ?Subject $subject = null, ?Mention $mention = null): MentionMatch
{
    tenancy()->initialize($tenant);
    $subject ??= Subject::factory()->create();
    $mention ??= Mention::factory()->create(['delitos' => ['estafa'], 'resumen' => 'Resumen de prueba.']);
    $coincidencia = MentionMatch::factory()->create(['subject_id' => $subject->id, 'mention_id' => $mention->id] + $match);
    tenancy()->end();

    return $coincidencia;
}

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

afterEach(function () {
    Carbon::setTestNow();
});

it('bandeja sin_propuesta: solo pendientes sin propuesta, las mas antiguas primero, con su contexto', function () {
    $tenant = Tenant::create();
    $user = usuarioDashboardConRol($tenant, 'analista');

    Carbon::setTestNow('2026-09-20 10:00:00');
    $vieja = coincidenciaEn($tenant);
    Carbon::setTestNow('2026-09-22 10:00:00');
    $nueva = coincidenciaEn($tenant);
    coincidenciaEn($tenant, ['propuesta_estado' => 'homonimo', 'propuesta_en' => now()]);
    coincidenciaEn($tenant, ['estado' => 'confirmado', 'resuelto_en' => now()]);

    $respuesta = $this->actingAs($user)
        ->getJson('/api/coincidencias?bandeja=sin_propuesta')
        ->assertOk()
        ->assertJsonPath('data.*.id', [$vieja->id, $nueva->id]);

    $primera = $respuesta->json('data.0');
    expect($primera['subject'])->toHaveKeys(['id', 'nombre_canonico', 'nivel_riesgo'])
        ->and($primera['mention'])->toHaveKeys(['nombre_extraido', 'rol', 'delitos', 'resumen', 'origen'])
        ->and($primera['mention']['article'])->toHaveKeys(['url', 'titulo', 'medio']);
});

it('bandeja esperan_resolucion: pendientes con propuesta, con quien propuso', function () {
    $tenant = Tenant::create();
    $user = usuarioDashboardConRol($tenant, 'oficial_cumplimiento');
    $analista = usuarioDashboardConRol($tenant, 'analista');
    coincidenciaEn($tenant);
    $propuesta = coincidenciaEn($tenant, [
        'propuesta_estado' => 'falso_positivo',
        'propuesta_por' => $analista->id,
        'propuesta_en' => now(),
    ]);

    $this->actingAs($user)
        ->getJson('/api/coincidencias?bandeja=esperan_resolucion')
        ->assertOk()
        ->assertJsonPath('data.*.id', [$propuesta->id])
        ->assertJsonPath('data.0.propuesta_estado', 'falso_positivo')
        ->assertJsonPath('data.0.propuesta_por_usuario.name', $analista->name)
        ->assertJsonMissingPath('data.0.propuesta_por_usuario.email');
});

it('no muestra coincidencias de otro tenant', function () {
    $tenant = Tenant::create();
    $user = usuarioDashboardConRol($tenant, 'lectura');
    coincidenciaEn(Tenant::create());

    $this->actingAs($user)->getJson('/api/coincidencias?bandeja=sin_propuesta')->assertJsonCount(0, 'data');
});

it('no expone el search_result de otro tenant a traves de una mencion compartida (articles/mentions son globales)', function () {
    $tenantA = Tenant::create();
    $tenantB = Tenant::create();
    $userB = usuarioDashboardConRol($tenantB, 'lectura');

    // La mencion salio de un search_result del tenant A (el primero que
    // encontro el articulo); el tenant B tiene su propio match sobre ella.
    tenancy()->initialize($tenantA);
    $resultadoA = SearchResult::factory()->for(Subject::factory(), 'subject')->create(['titulo' => 'Titulo privado de A']);
    tenancy()->end();
    $mention = Mention::factory()->create(['search_result_id' => $resultadoA->id, 'article_id' => Article::factory()]);
    coincidenciaEn($tenantB, mention: $mention);

    $respuesta = $this->actingAs($userB)->getJson('/api/coincidencias?bandeja=sin_propuesta')->assertOk();

    expect($respuesta->json('data.0.mention.search_result'))->toBeNull()
        ->and(json_encode($respuesta->json()))->not->toContain('Titulo privado de A');
});

it('rechaza una bandeja invalida', function () {
    $user = usuarioDashboardConRol(Tenant::create(), 'lectura');

    $this->actingAs($user)->getJson('/api/coincidencias?bandeja=otra')->assertUnprocessable();
});

it('el resumen del inicio cuenta coincidencias, seguimientos y resultados en GAP del tenant', function () {
    Carbon::setTestNow('2026-09-25 15:00:00');
    $tenant = Tenant::create();
    $user = usuarioDashboardConRol($tenant, 'lectura');

    coincidenciaEn($tenant);
    coincidenciaEn($tenant);
    coincidenciaEn($tenant, ['propuesta_estado' => 'homonimo', 'propuesta_en' => now()]);
    coincidenciaEn($tenant, ['estado' => 'confirmado']);
    coincidenciaEn(Tenant::create()); // otro tenant

    tenancy()->initialize($tenant);
    $vencido = Subject::factory()->create();
    $pronto = Subject::factory()->create();
    Subject::whereKey($vencido->id)->update(['proximo_seguimiento_en' => '2026-09-20']);
    Subject::whereKey($pronto->id)->update(['proximo_seguimiento_en' => '2026-09-30']);
    SearchResult::factory()->for($vencido, 'subject')->create(['estado' => EstadoSearchResult::Gap]);
    SearchResult::factory()->for($vencido, 'subject')->create(['estado' => EstadoSearchResult::Nuevo]);
    tenancy()->end();

    $this->actingAs($user)
        ->getJson('/api/inicio/resumen')
        ->assertOk()
        ->assertJson([
            'coincidencias_sin_propuesta' => 2,
            'coincidencias_esperan_resolucion' => 1,
            'seguimientos_vencidos' => 1,
            'seguimientos_proximos_7_dias' => 1,
            'resultados_gap' => 1,
        ]);
});

it('superadmin recibe 403 en coincidencias e inicio', function () {
    $user = User::factory()->create();
    $user->assignRole('superadmin');

    $this->actingAs($user)->getJson('/api/coincidencias')->assertForbidden();
    $this->actingAs($user)->getJson('/api/inicio/resumen')->assertForbidden();
});
