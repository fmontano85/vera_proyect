<?php

declare(strict_types=1);

use App\Jobs\ReconciliarIndiceSubjectsJob;
use App\Models\Subject;
use App\Models\SubjectAlias;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Scout\EngineManager;
use Meilisearch\Client;
use Spatie\Activitylog\Models\Activity;
use Stancl\Tenancy\Database\Models\Tenant;

/**
 * Gestion de la lista de vigilancia (UI de alta, aliases y datos del
 * subject). Decisiones del usuario 2026-09-25: alta/aliases/datos los
 * editan admin, oficial_cumplimiento y analista; activar/desactivar solo
 * oficial_cumplimiento y admin. Los aliases son parte del indice de
 * Meilisearch que usa el matching: todo cambio reindexa, y si Meilisearch
 * no responde no se guarda nada (nunca un indice desactualizado en
 * silencio).
 */
function usuarioListaConRol(Tenant $tenant, string $rol): User
{
    $user = User::factory()->create();
    $user->forceFill(['tenant_id' => $tenant->id])->save();
    $user->assignRole($rol);

    return $user;
}

function subjectEnTenant(Tenant $tenant, array $atributos = [], array $aliases = []): Subject
{
    tenancy()->initialize($tenant);
    $subject = Subject::factory()->create($atributos);
    foreach ($aliases as $alias) {
        SubjectAlias::factory()->for($subject, 'subject')->create(['nombre' => $alias]);
    }
    tenancy()->end();

    return $subject;
}

/** Meilisearch indexa de forma asincrona. */
function esperarIndexado(callable $condicion): bool
{
    for ($i = 0; $i < 30; $i++) {
        if ($condicion()) {
            return true;
        }
        usleep(100_000);
    }

    return false;
}

function buscarEnIndice(Tenant $tenant, string $texto): array
{
    return Subject::search($texto)->where('tenant_id', $tenant->id)->raw()['hits'] ?? [];
}

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

// --- Listado ---------------------------------------------------------

it('lista solo activos por defecto, con cantidad de aliases y bloque de seguimiento', function () {
    $tenant = Tenant::create();
    $user = usuarioListaConRol($tenant, 'lectura');
    $activo = subjectEnTenant($tenant, ['nombre_canonico' => 'Ana Activa'], ['Anita', 'A. Activa']);
    subjectEnTenant($tenant, ['nombre_canonico' => 'Ines Inactiva', 'activo' => false]);

    $this->actingAs($user)
        ->getJson('/api/subjects')
        ->assertOk()
        ->assertJsonPath('data.*.id', [$activo->id])
        ->assertJsonPath('data.0.aliases_count', 2)
        ->assertJsonPath('data.0.seguimiento.frecuencia_dias', 180);
});

it('filtra por estado, nivel y busca por nombre o alias', function () {
    $tenant = Tenant::create();
    $user = usuarioListaConRol($tenant, 'lectura');
    $alto = subjectEnTenant($tenant, ['nombre_canonico' => 'Roberto Alto', 'nivel_riesgo' => 'alto'], ['El Tigre']);
    $bajo = subjectEnTenant($tenant, ['nombre_canonico' => 'Maria Baja', 'nivel_riesgo' => 'bajo']);
    $inactivo = subjectEnTenant($tenant, ['nombre_canonico' => 'Pedro Inactivo', 'activo' => false]);

    $this->actingAs($user)->getJson('/api/subjects?estado=inactivos')->assertJsonPath('data.*.id', [$inactivo->id]);
    $this->actingAs($user)->getJson('/api/subjects?estado=todos')->assertJsonCount(3, 'data');
    $this->actingAs($user)->getJson('/api/subjects?nivel=bajo')->assertJsonPath('data.*.id', [$bajo->id]);
    $this->actingAs($user)->getJson('/api/subjects?buscar=tigre')->assertJsonPath('data.*.id', [$alto->id]);
    $this->actingAs($user)->getJson('/api/subjects?buscar=maria')->assertJsonPath('data.*.id', [$bajo->id]);
});

it('la busqueda trata % y _ como texto, no como comodines', function () {
    $tenant = Tenant::create();
    $user = usuarioListaConRol($tenant, 'lectura');
    subjectEnTenant($tenant, ['nombre_canonico' => 'Juan Perez']);

    $this->actingAs($user)->getJson('/api/subjects?buscar=%25')->assertJsonCount(0, 'data');
    $this->actingAs($user)->getJson('/api/subjects?buscar=_')->assertJsonCount(0, 'data');
});

it('no lista subjects de otro tenant aunque coincida la busqueda', function () {
    $tenant = Tenant::create();
    $user = usuarioListaConRol($tenant, 'lectura');
    subjectEnTenant(Tenant::create(), ['nombre_canonico' => 'Ajeno Perez']);

    $this->actingAs($user)->getJson('/api/subjects?buscar=ajeno&estado=todos')->assertJsonCount(0, 'data');
});

// --- Alta ------------------------------------------------------------

it('el alta acepta aliases, los guarda en la misma transaccion y el subject queda buscable por alias en Meilisearch', function () {
    $tenant = Tenant::create();
    $user = usuarioListaConRol($tenant, 'analista');

    $respuesta = $this->actingAs($user)->postJson('/api/subjects', [
        'tipo' => 'natural',
        'nombre_canonico' => 'Xochilt Beatriz Arevalo',
        'nivel_riesgo' => 'medio',
        'aliases' => ['La Chiqui Arevalo', 'X. B. Arevalo'],
    ])->assertCreated()
        ->assertJsonCount(2, 'aliases');

    tenancy()->initialize($tenant);
    expect(SubjectAlias::where('subject_id', $respuesta->json('id'))->pluck('nombre')->sort()->values()->all())
        ->toBe(['La Chiqui Arevalo', 'X. B. Arevalo']);
    tenancy()->end();

    expect(esperarIndexado(fn () => collect(buscarEnIndice($tenant, 'La Chiqui Arevalo'))
        ->contains(fn ($hit) => in_array('La Chiqui Arevalo', $hit['aliases'] ?? [], true))))->toBeTrue();
});

it('el alta rechaza aliases repetidos', function () {
    $user = usuarioListaConRol(Tenant::create(), 'analista');

    $this->actingAs($user)->postJson('/api/subjects', [
        'tipo' => 'natural',
        'nombre_canonico' => 'Juan Perez',
        'aliases' => ['Juanito', 'Juanito'],
    ])->assertUnprocessable()->assertJsonValidationErrors(['aliases.0']);
});

// --- Edicion de datos ------------------------------------------------

it('analista edita nombre, tipo y documento; queda auditado', function () {
    $tenant = Tenant::create();
    $user = usuarioListaConRol($tenant, 'analista');
    $subject = subjectEnTenant($tenant, ['nombre_canonico' => 'Jose Perz', 'tipo' => 'natural']);

    $this->actingAs($user)
        ->patchJson("/api/subjects/{$subject->id}", [
            'nombre_canonico' => 'Jose Perez',
            'tipo' => 'juridica',
            'documento' => '0614-010190-101-1',
        ])
        ->assertOk()
        ->assertJsonPath('nombre_canonico', 'Jose Perez')
        ->assertJsonPath('tipo', 'juridica')
        ->assertJsonPath('documento', '0614-010190-101-1');

    tenancy()->initialize($tenant);
    $cambio = Activity::where('subject_type', Subject::class)->where('subject_id', $subject->id)->where('event', 'updated')->latest('id')->first();
    expect($cambio->attribute_changes['attributes'])->toMatchArray(['nombre_canonico' => 'Jose Perez', 'tipo' => 'juridica']);
    tenancy()->end();
});

it('analista no puede activar ni desactivar; oficial si, y queda auditado', function () {
    $tenant = Tenant::create();
    $analista = usuarioListaConRol($tenant, 'analista');
    $oficial = usuarioListaConRol($tenant, 'oficial_cumplimiento');
    $subject = subjectEnTenant($tenant);

    $this->actingAs($analista)->patchJson("/api/subjects/{$subject->id}", ['activo' => false])->assertForbidden();

    $this->actingAs($oficial)->patchJson("/api/subjects/{$subject->id}", ['activo' => false])
        ->assertOk()
        ->assertJsonPath('activo', false);
    $this->actingAs($oficial)->patchJson("/api/subjects/{$subject->id}", ['activo' => true])
        ->assertOk()
        ->assertJsonPath('activo', true);

    tenancy()->initialize($tenant);
    expect(Activity::where('subject_type', Subject::class)->where('subject_id', $subject->id)->where('event', 'updated')->count())->toBe(2);
    tenancy()->end();
});

it('lectura no puede editar datos del subject', function () {
    $tenant = Tenant::create();
    $user = usuarioListaConRol($tenant, 'lectura');
    $subject = subjectEnTenant($tenant);

    $this->actingAs($user)->patchJson("/api/subjects/{$subject->id}", ['nombre_canonico' => 'X'])->assertForbidden();
});

// --- Aliases ---------------------------------------------------------

it('agregar un alias lo guarda, lo audita y reindexa al subject en Meilisearch', function () {
    $tenant = Tenant::create();
    $user = usuarioListaConRol($tenant, 'analista');
    $subject = subjectEnTenant($tenant, ['nombre_canonico' => 'Wilfredo Antonio Zelaya']);

    $this->actingAs($user)
        ->postJson("/api/subjects/{$subject->id}/aliases", ['nombre' => 'El Zurdo Zelaya'])
        ->assertCreated()
        ->assertJsonPath('aliases.0.nombre', 'El Zurdo Zelaya');

    tenancy()->initialize($tenant);
    expect(Activity::where('subject_type', SubjectAlias::class)->where('event', 'created')->count())->toBe(1);
    tenancy()->end();

    expect(esperarIndexado(fn () => collect(buscarEnIndice($tenant, 'Zurdo Zelaya'))
        ->contains(fn ($hit) => in_array('El Zurdo Zelaya', $hit['aliases'] ?? [], true))))->toBeTrue();
});

it('quitar un alias lo borra, lo audita y reindexa sin ese alias', function () {
    $tenant = Tenant::create();
    $user = usuarioListaConRol($tenant, 'analista');
    $subject = subjectEnTenant($tenant, ['nombre_canonico' => 'Heriberto Quintanilla Soto'], ['El Guero Quintanilla']);
    tenancy()->initialize($tenant);
    $alias = SubjectAlias::where('subject_id', $subject->id)->sole();
    tenancy()->end();

    $this->actingAs($user)
        ->deleteJson("/api/subjects/{$subject->id}/aliases/{$alias->id}")
        ->assertOk()
        ->assertJsonCount(0, 'aliases');

    tenancy()->initialize($tenant);
    expect(SubjectAlias::count())->toBe(0)
        ->and(Activity::where('subject_type', SubjectAlias::class)->where('event', 'deleted')->count())->toBe(1);
    tenancy()->end();

    expect(esperarIndexado(fn () => collect(buscarEnIndice($tenant, 'Heriberto Quintanilla'))
        ->contains(fn ($hit) => $hit['id'] === $subject->id && ($hit['aliases'] ?? []) === [])))->toBeTrue();
});

it('rechaza un alias repetido para el mismo subject', function () {
    $tenant = Tenant::create();
    $user = usuarioListaConRol($tenant, 'analista');
    $subject = subjectEnTenant($tenant, [], ['Chepe']);

    $this->actingAs($user)
        ->postJson("/api/subjects/{$subject->id}/aliases", ['nombre' => 'Chepe'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['nombre']);
});

it('no permite quitar un alias usando el id de un subject distinto', function () {
    $tenant = Tenant::create();
    $user = usuarioListaConRol($tenant, 'analista');
    $subjectA = subjectEnTenant($tenant, [], ['Alias de A']);
    $subjectB = subjectEnTenant($tenant);
    tenancy()->initialize($tenant);
    $aliasDeA = SubjectAlias::where('subject_id', $subjectA->id)->sole();
    tenancy()->end();

    $this->actingAs($user)->deleteJson("/api/subjects/{$subjectB->id}/aliases/{$aliasDeA->id}")->assertNotFound();
});

it('lectura no puede agregar aliases y no se tocan aliases de otro tenant', function () {
    $tenant = Tenant::create();
    $lectura = usuarioListaConRol($tenant, 'lectura');
    $analista = usuarioListaConRol($tenant, 'analista');
    $propio = subjectEnTenant($tenant);
    $ajeno = subjectEnTenant(Tenant::create());

    $this->actingAs($lectura)->postJson("/api/subjects/{$propio->id}/aliases", ['nombre' => 'X'])->assertForbidden();
    $this->actingAs($analista)->postJson("/api/subjects/{$ajeno->id}/aliases", ['nombre' => 'X'])->assertNotFound();
});

it('si Meilisearch no responde, agregar un alias falla con 503 y no guarda nada', function () {
    $tenant = Tenant::create();
    $user = usuarioListaConRol($tenant, 'analista');
    $subject = subjectEnTenant($tenant);

    config(['scout.meilisearch.host' => 'http://127.0.0.1:1']);
    app()->forgetInstance(EngineManager::class);
    app()->forgetInstance(Client::class);

    $this->actingAs($user)
        ->postJson("/api/subjects/{$subject->id}/aliases", ['nombre' => 'Alias Sin Indice'])
        ->assertStatus(503);

    tenancy()->initialize($tenant);
    expect(SubjectAlias::count())->toBe(0);
    tenancy()->end();
});

// --- Correcciones del code-review ------------------------------------

it('analista puede mandar activo sin cambiarlo junto con otros campos (solo cambiarlo exige oficial/admin)', function () {
    $tenant = Tenant::create();
    $user = usuarioListaConRol($tenant, 'analista');
    $subject = subjectEnTenant($tenant, ['nombre_canonico' => 'Nombre Viejo']);

    $this->actingAs($user)
        ->patchJson("/api/subjects/{$subject->id}", ['nombre_canonico' => 'Nombre Nuevo', 'activo' => true])
        ->assertOk()
        ->assertJsonPath('nombre_canonico', 'Nombre Nuevo');
});

it('un alias repetido enviado en carrera responde 422, no 500', function () {
    $tenant = Tenant::create();
    $user = usuarioListaConRol($tenant, 'analista');
    $subject = subjectEnTenant($tenant);

    // Simula la carrera: el alias aparece en la BD despues de la validacion
    // (el unique de la BD es la ultima barrera).
    SubjectAlias::creating(function (SubjectAlias $alias) {
        if ($alias->nombre === 'Carrera' && ! SubjectAlias::where('subject_id', $alias->subject_id)->where('nombre', 'Carrera')->exists()) {
            DB::table('subject_aliases')->insert([
                'tenant_id' => $alias->tenant_id, 'subject_id' => $alias->subject_id, 'nombre' => 'Carrera',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    });

    $this->actingAs($user)
        ->postJson("/api/subjects/{$subject->id}/aliases", ['nombre' => 'Carrera'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['nombre']);
});

it('no permite mas de 20 aliases por subject ni un alias igual al nombre canonico', function () {
    $tenant = Tenant::create();
    $user = usuarioListaConRol($tenant, 'analista');
    $subject = subjectEnTenant($tenant, ['nombre_canonico' => 'Juan Perez'], array_map(fn ($i) => "Alias {$i}", range(1, 20)));
    $otro = subjectEnTenant($tenant, ['nombre_canonico' => 'Maria Lopez']);

    $this->actingAs($user)->postJson("/api/subjects/{$subject->id}/aliases", ['nombre' => 'Alias 21'])
        ->assertUnprocessable()->assertJsonValidationErrors(['nombre']);
    $this->actingAs($user)->postJson("/api/subjects/{$otro->id}/aliases", ['nombre' => 'maria lopez'])
        ->assertUnprocessable()->assertJsonValidationErrors(['nombre']);
});

it('pagina de forma estable entre homonimos (desempate por id)', function () {
    $tenant = Tenant::create();
    $user = usuarioListaConRol($tenant, 'lectura');
    $ids = collect(range(1, 20))->map(fn () => subjectEnTenant($tenant, ['nombre_canonico' => 'Jose Hernandez'])->id);

    $pagina1 = $this->actingAs($user)->getJson('/api/subjects?page=1')->json('data.*.id');
    $pagina2 = $this->actingAs($user)->getJson('/api/subjects?page=2')->json('data.*.id');

    expect(array_merge($pagina1, $pagina2))->toBe($ids->all());
});

it('encuentra nombres que contienen % o _ buscandolos literalmente', function () {
    $tenant = Tenant::create();
    $user = usuarioListaConRol($tenant, 'lectura');
    $porcentaje = subjectEnTenant($tenant, ['nombre_canonico' => 'Inversiones 100% Seguras']);
    $guion = subjectEnTenant($tenant, ['nombre_canonico' => 'Grupo_Norte']);
    subjectEnTenant($tenant, ['nombre_canonico' => 'Grupo Norte Dos']);

    $this->actingAs($user)->getJson('/api/subjects?buscar=100%25')->assertJsonPath('data.*.id', [$porcentaje->id]);
    $this->actingAs($user)->getJson('/api/subjects?buscar=o_N')->assertJsonPath('data.*.id', [$guion->id]);
});

it('la reconciliacion diaria del indice indexa activos faltantes y saca inactivos (sin servicios de pago)', function () {
    Http::preventStrayRequests();
    $tenant = Tenant::create();

    tenancy()->initialize($tenant);
    $faltante = Subject::withoutSyncingToSearch(fn () => Subject::factory()->create(['nombre_canonico' => 'Evaristo Faltante Ochoa']));
    $inactivo = Subject::factory()->create(['nombre_canonico' => 'Leonidas Sobrante Mejia']);
    tenancy()->end();
    expect(esperarIndexado(fn () => buscarEnIndice($tenant, 'Leonidas Sobrante Mejia') !== []))->toBeTrue();

    tenancy()->initialize($tenant);
    Subject::withoutSyncingToSearch(fn () => $inactivo->update(['activo' => false]));
    tenancy()->end();

    (new ReconciliarIndiceSubjectsJob)->handle();

    expect(esperarIndexado(fn () => collect(buscarEnIndice($tenant, 'Evaristo Faltante Ochoa'))->contains('id', $faltante->id)))->toBeTrue()
        ->and(esperarIndexado(fn () => ! collect(buscarEnIndice($tenant, 'Leonidas Sobrante Mejia'))->contains('id', $inactivo->id)))->toBeTrue();
});

it('la reconciliacion queda programada diariamente', function () {
    $evento = collect(app(Schedule::class)->events())
        ->first(fn ($e) => str_contains((string) $e->description, ReconciliarIndiceSubjectsJob::class));

    expect($evento)->not->toBeNull()
        ->and($evento->expression)->toBe('0 3 * * *')
        ->and($evento->timezone)->toBe('America/El_Salvador');
});
