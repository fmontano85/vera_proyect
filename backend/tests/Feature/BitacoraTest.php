<?php

declare(strict_types=1);

use App\Enums\EstadoSearchResult;
use App\Models\SearchResult;
use App\Models\SearchTag;
use App\Models\Source;
use App\Models\Subject;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;
use Stancl\Tenancy\Database\Models\Tenant;

/**
 * Bitacora de accesos (seccion 3.9, punto 7 - bloque A). El admin ve la de
 * su tenant completa; el superadmin la de todos, sin datos personales
 * (opcion A, decision del usuario 2026-09-28).
 */
function subjectDe(Tenant $tenant, array $atributos = []): Subject
{
    tenancy()->initialize($tenant);
    $subject = Subject::factory()->create($atributos);
    tenancy()->end();

    return $subject;
}

function resultadoDe(Tenant $tenant, array $atributos = []): SearchResult
{
    tenancy()->initialize($tenant);
    $resultado = SearchResult::factory()->for(Subject::factory(), 'subject')->create($atributos);
    tenancy()->end();

    return $resultado;
}

beforeEach(fn () => $this->seed(RoleSeeder::class));

it('guarda el tenant de cada registro de la bitacora', function () {
    $tenant = Tenant::create();
    $subject = subjectDe($tenant);

    $registro = Activity::where('subject_type', Subject::class)->where('subject_id', $subject->id)->sole();

    expect($registro->tenant_id)->toBe($tenant->id);
});

it('asigna el tenant del causante cuando no hay tenancy inicializada (login)', function () {
    $tenant = Tenant::create();
    $user = usuarioDeTenant($tenant, 'analista', ['password' => bcrypt('clave-correcta-123')]);

    comoFrontend()->postJson('/api/login', ['email' => $user->email, 'password' => 'clave-correcta-123'])->assertOk();

    $registro = Activity::where('event', 'inicio_sesion')->sole();
    expect($registro->tenant_id)->toBe($tenant->id)
        ->and((int) $registro->causer_id)->toBe($user->id);
});

it('registra el cierre de sesion', function () {
    $tenant = Tenant::create();
    $user = usuarioDeTenant($tenant, 'analista', ['password' => bcrypt('clave-correcta-123')]);
    // Login real (no actingAs): logout() necesita una sesion que invalidar.
    $cliente = comoFrontend();
    $cliente->postJson('/api/login', ['email' => $user->email, 'password' => 'clave-correcta-123'])->assertOk();

    $cliente->postJson('/api/logout')->assertNoContent();

    expect(Activity::where('event', 'cierre_sesion')->where('causer_id', $user->id)->exists())->toBeTrue();
});

it('registra la consulta puntual sobre la persona', function () {
    Bus::fake();
    $tenant = Tenant::create();
    $user = usuarioDeTenant($tenant, 'analista');
    $subject = subjectDe($tenant);
    Source::factory()->create(['tipo' => 'brave', 'activo' => true]);

    $this->actingAs($user)->postJson("/api/subjects/{$subject->id}/buscar")->assertStatus(202);

    $registro = Activity::where('event', 'consulta_puntual')->sole();
    expect($registro->subject_type)->toBe(Subject::class)
        ->and((int) $registro->subject_id)->toBe($subject->id)
        ->and($registro->tenant_id)->toBe($tenant->id);
});

it('registra la busqueda por tags con los tags usados', function () {
    Bus::fake();
    $tenant = Tenant::create();
    $user = usuarioDeTenant($tenant, 'analista');
    Source::factory()->create(['tipo' => 'brave', 'activo' => true, 'config' => ['dominios' => ['diario1.com']]]);
    tenancy()->initialize($tenant);
    $tag = SearchTag::factory()->create(['nombre' => 'tag de prueba bitacora', 'activo' => true]);
    tenancy()->end();

    $this->actingAs($user)->postJson('/api/busquedas-tags', ['tag_ids' => [$tag->id]])->assertStatus(202);

    $registro = Activity::where('event', 'busqueda_tags')->sole();
    expect($registro->getProperty('tags'))->toBe(['tag de prueba bitacora'])
        ->and($registro->tenant_id)->toBe($tenant->id);
});

it('registra la extraccion solicitada y el descarte de un resultado', function () {
    Bus::fake();
    $tenant = Tenant::create();
    $user = usuarioDeTenant($tenant, 'analista');
    $aExtraer = resultadoDe($tenant, ['estado' => EstadoSearchResult::Nuevo]);
    $aDescartar = resultadoDe($tenant, ['estado' => EstadoSearchResult::Nuevo]);

    $this->actingAs($user)->postJson("/api/resultados/{$aExtraer->id}/extraer")->assertOk();
    $this->actingAs($user)->postJson("/api/resultados/{$aDescartar->id}/descartar")->assertOk();

    expect(Activity::where('event', 'extraccion_solicitada')->where('subject_id', $aExtraer->id)->exists())->toBeTrue()
        ->and(Activity::where('event', 'resultado_descartado')->where('subject_id', $aDescartar->id)->exists())->toBeTrue();
});

it('registra la descarga de evidencia con su tipo', function () {
    Storage::fake(config('vera.evidencia_manual_disk'));
    $tenant = Tenant::create();
    $user = usuarioDeTenant($tenant, 'lectura');
    $resultado = resultadoDe($tenant, ['estado' => EstadoSearchResult::Extraido]);
    $resultado->forceFill(['evidencia_manual_path' => 'tenants/x/evidencia-manual/1.pdf'])->save();
    Storage::disk(config('vera.evidencia_manual_disk'))->put('tenants/x/evidencia-manual/1.pdf', '%PDF-1.4');

    $this->actingAs($user)->get("/api/resultados/{$resultado->id}/evidencia/manual")->assertOk();

    $registro = Activity::where('event', 'evidencia_descargada')->sole();
    expect($registro->getProperty('tipo'))->toBe('manual')
        ->and((int) $registro->subject_id)->toBe($resultado->id);
});

it('no registra una descarga de evidencia que no existe', function () {
    $tenant = Tenant::create();
    $user = usuarioDeTenant($tenant, 'lectura');
    $resultado = resultadoDe($tenant);

    $this->actingAs($user)->get("/api/resultados/{$resultado->id}/evidencia/manual")->assertNotFound();

    expect(Activity::where('event', 'evidencia_descargada')->exists())->toBeFalse();
});

it('solo el admin del tenant ve la bitacora del tenant', function (string $rol) {
    $tenant = Tenant::create();
    $user = usuarioDeTenant($tenant, $rol);

    $this->actingAs($user)->getJson('/api/bitacora')->assertForbidden();
})->with(['oficial_cumplimiento', 'analista', 'lectura']);

it('el admin ve la bitacora completa de su tenant y nunca la de otro', function () {
    $tenant = Tenant::create();
    $otro = Tenant::create();
    $admin = usuarioDeTenant($tenant, 'admin');
    $propio = subjectDe($tenant, ['nombre_canonico' => 'Persona Propia']);
    subjectDe($otro, ['nombre_canonico' => 'Persona Ajena']);

    $respuesta = $this->actingAs($admin)->getJson('/api/bitacora')->assertOk();

    $ids = collect($respuesta->json('data'))->pluck('objeto.id')->all();
    expect($ids)->toContain($propio->id)
        ->and($respuesta->getContent())->toContain('Persona Propia')
        ->and($respuesta->getContent())->not->toContain('Persona Ajena');
});

it('filtra la bitacora del tenant por evento y por fechas', function () {
    Bus::fake();
    $tenant = Tenant::create();
    $admin = usuarioDeTenant($tenant, 'admin');
    $subject = subjectDe($tenant);
    Source::factory()->create(['tipo' => 'brave', 'activo' => true]);
    $this->actingAs($admin)->postJson("/api/subjects/{$subject->id}/buscar")->assertStatus(202);

    $porEvento = $this->actingAs($admin)->getJson('/api/bitacora?evento=consulta_puntual')->assertOk();
    expect($porEvento->json('data'))->toHaveCount(1)
        ->and($porEvento->json('data.0.evento'))->toBe('consulta_puntual');

    $manana = now()->addDay()->toDateString();
    expect($this->actingAs($admin)->getJson("/api/bitacora?desde={$manana}")->json('data'))->toBe([]);
});

it('lista los eventos disponibles para filtrar la bitacora del tenant', function () {
    $tenant = Tenant::create();
    $admin = usuarioDeTenant($tenant, 'admin');
    subjectDe($tenant);

    $this->actingAs($admin)->getJson('/api/bitacora/eventos')->assertOk()->assertJson(['created']);
});

it('solo el superadmin ve la bitacora global', function (string $rol) {
    $tenant = Tenant::create();
    $user = usuarioDeTenant($tenant, $rol);

    $this->actingAs($user)->getJson('/api/superadmin/bitacora')->assertForbidden();
})->with(['admin', 'oficial_cumplimiento', 'analista', 'lectura']);

it('el superadmin ve la bitacora de todos los tenants sin datos personales', function () {
    $tenant = Tenant::create(['name' => 'Banco Uno']);
    $subject = subjectDe($tenant, ['nombre_canonico' => 'Nombre Secreto', 'documento' => '01234567-8']);
    $admin = usuarioDeTenant($tenant, 'admin');
    $this->actingAs($admin)->patchJson("/api/subjects/{$subject->id}", ['nivel_riesgo' => 'alto'])->assertOk();

    $respuesta = $this->actingAs(superadmin())->getJson('/api/superadmin/bitacora')->assertOk();

    expect($respuesta->getContent())->not->toContain('Nombre Secreto')
        ->and($respuesta->getContent())->not->toContain('01234567-8')
        ->and($respuesta->json('data.0'))->not->toHaveKeys(['cambios', 'propiedades', 'descripcion']);

    $dePersona = collect($respuesta->json('data'))->firstWhere('objeto.tipo', 'persona');
    expect($dePersona['objeto']['id'])->toBe($subject->id)
        ->and($dePersona['tenant']['name'])->toBe('Banco Uno');
});

it('el superadmin filtra la bitacora global por tenant', function () {
    $uno = Tenant::create();
    $dos = Tenant::create();
    subjectDe($uno);
    subjectDe($dos);

    $respuesta = $this->actingAs(superadmin())->getJson("/api/superadmin/bitacora?tenant_id={$uno->id}")->assertOk();

    expect(collect($respuesta->json('data'))->pluck('tenant.id')->unique()->all())->toBe([$uno->id]);
});

it('un registro hecho por el superadmin sobre un modelo de un tenant queda con el tenant de ese modelo', function () {
    $respuesta = $this->actingAs(superadmin())->postJson('/api/superadmin/tenants', [
        'name' => 'Banco Nuevo', 'admin_name' => 'Primer Admin',
        'admin_email' => 'primer@banco-nuevo.test', 'admin_password' => 'una-clave-larga-123',
    ])->assertCreated();

    $admin = App\Models\User::where('email', 'primer@banco-nuevo.test')->sole();
    $registro = Activity::where('subject_type', App\Models\User::class)->where('subject_id', $admin->id)->where('event', 'created')->sole();

    expect($registro->tenant_id)->toBe($respuesta->json('tenant.id'));
});
