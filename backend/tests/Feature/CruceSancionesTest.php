<?php

declare(strict_types=1);

use App\Jobs\MatchSanctionsJob;
use App\Models\SanctionEntry;
use App\Models\SanctionList;
use App\Models\SanctionMatch;
use App\Models\Subject;
use App\Models\SubjectAlias;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;
use Stancl\Tenancy\Database\Models\Tenant;

/** Meilisearch indexa de forma asincrona: se reintenta un poco. */
function esperarSancionIndexada(string $nombre): void
{
    for ($i = 0; $i < 40; $i++) {
        if (SanctionEntry::search($nombre)->get()->isNotEmpty()) {
            return;
        }
        usleep(100_000);
    }
}

/**
 * sanction_lists.codigo es unico y el factory lo sortea entre 3 valores:
 * varias entradas en un mismo test deben compartir UNA lista o el sorteo
 * colisiona de forma intermitente.
 */
function hallazgoSancion(array $atributos = [], ?SanctionEntry $entrada = null): SanctionMatch
{
    static $lista = null;
    $lista = SanctionList::firstOrCreate(['codigo' => 'ofac_sdn']);
    $entrada ??= SanctionEntry::factory()->create(['sanction_list_id' => $lista->id]);

    return SanctionMatch::factory()->create([...$atributos, 'sanction_entry_id' => $entrada->id]);
}

function usuarioSanciones(Tenant $tenant, string $rol): User
{
    $user = User::factory()->create();
    $user->forceFill(['tenant_id' => $tenant->id])->save();
    $user->assignRole($rol);

    return $user;
}

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    config(['vera.sanciones_score_minimo' => 85]);
});

it('cruza un subject activo contra las sanciones y deja el hallazgo pendiente', function () {
    $tenant = Tenant::create();
    $nombre = 'Zzyzx Qwertyuiop Mbabazi';
    $entrada = SanctionEntry::factory()->create(['nombre' => $nombre]);
    esperarSancionIndexada($nombre);

    tenancy()->initialize($tenant);
    $subject = Subject::factory()->create(['nombre_canonico' => $nombre]);
    tenancy()->end();

    (new MatchSanctionsJob)->handle();

    tenancy()->initialize($tenant);
    $match = SanctionMatch::where('subject_id', $subject->id)->where('sanction_entry_id', $entrada->id)->first();
    tenancy()->end();

    expect($match)->not->toBeNull()
        ->and($match->estado)->toBe('pendiente')
        ->and((float) $match->score)->toBeGreaterThanOrEqual(85.0)
        ->and($match->tenant_id)->toBe($tenant->id);
});

it('tambien cruza por los aliases del subject', function () {
    $tenant = Tenant::create();
    $entrada = SanctionEntry::factory()->create(['nombre' => 'Vvxkq Rrtplm Ooiuyt']);
    esperarSancionIndexada('Vvxkq Rrtplm Ooiuyt');

    tenancy()->initialize($tenant);
    $subject = Subject::factory()->create(['nombre_canonico' => 'Nombre Completamente Distinto']);
    SubjectAlias::factory()->for($subject, 'subject')->create(['nombre' => 'Vvxkq Rrtplm Ooiuyt']);
    tenancy()->end();

    (new MatchSanctionsJob)->handle();

    tenancy()->initialize($tenant);
    expect(SanctionMatch::where('sanction_entry_id', $entrada->id)->count())->toBe(1);
    tenancy()->end();
});

it('no genera hallazgos por debajo del umbral ni para subjects inactivos', function () {
    $tenant = Tenant::create();
    SanctionEntry::factory()->create(['nombre' => 'Kkjhgf Ddsaqw Pplmnb']);
    esperarSancionIndexada('Kkjhgf Ddsaqw Pplmnb');

    tenancy()->initialize($tenant);
    Subject::factory()->create(['nombre_canonico' => 'Alguien Sin Relacion Alguna']);
    Subject::factory()->create(['nombre_canonico' => 'Kkjhgf Ddsaqw Pplmnb', 'activo' => false]);
    tenancy()->end();

    (new MatchSanctionsJob)->handle();

    tenancy()->initialize($tenant);
    expect(SanctionMatch::count())->toBe(0);
    tenancy()->end();
});

it('es idempotente y no reabre un hallazgo ya resuelto', function () {
    $tenant = Tenant::create();
    $nombre = 'Xxcvbn Llkjhg Ttrewq';
    $entrada = SanctionEntry::factory()->create(['nombre' => $nombre]);
    esperarSancionIndexada($nombre);

    tenancy()->initialize($tenant);
    $subject = Subject::factory()->create(['nombre_canonico' => $nombre]);
    tenancy()->end();

    (new MatchSanctionsJob)->handle();

    tenancy()->initialize($tenant);
    SanctionMatch::sole()->forceFill(['estado' => 'falso_positivo'])->save();
    tenancy()->end();

    (new MatchSanctionsJob)->handle();

    tenancy()->initialize($tenant);
    expect(SanctionMatch::count())->toBe(1)->and(SanctionMatch::sole()->estado)->toBe('falso_positivo');
    tenancy()->end();
});

it('no cruza tenants: cada hallazgo queda en el tenant de su subject', function () {
    $a = Tenant::create();
    $b = Tenant::create();
    $nombre = 'Ggfdsa Hhjklp Uuyter';
    SanctionEntry::factory()->create(['nombre' => $nombre]);
    esperarSancionIndexada($nombre);

    tenancy()->initialize($a);
    Subject::factory()->create(['nombre_canonico' => $nombre]);
    tenancy()->end();

    (new MatchSanctionsJob)->handle();

    tenancy()->initialize($b);
    expect(SanctionMatch::count())->toBe(0);
    tenancy()->end();
});

it('MatchSanctionsJob usa la relacion aliases ya cargada, no una query por subject', function () {
    $tenant = Tenant::create();
    tenancy()->initialize($tenant);
    Subject::factory()->count(3)->create();
    tenancy()->end();

    $consultasAliases = 0;
    DB::listen(function ($query) use (&$consultasAliases) {
        if (str_contains($query->sql, 'subject_aliases')) {
            $consultasAliases++;
        }
    });

    (new MatchSanctionsJob)->handle();

    // 1 sola consulta de aliases (el eager load), no una por cada subject.
    expect($consultasAliases)->toBe(1);
});

it('un fallo real de Meilisearch al cruzar responde 503, no un error generico', function () {
    $tenant = Tenant::create();
    tenancy()->initialize($tenant);
    $subject = Subject::factory()->create();
    tenancy()->end();
    $user = usuarioSanciones($tenant, 'analista');

    $mock = Mockery::mock(\App\Services\Sanctions\CruceSanciones::class);
    $mock->shouldReceive('cruzar')->once()->andThrow(
        new \Meilisearch\Exceptions\CommunicationException('conexion caida')
    );
    $this->app->instance(\App\Services\Sanctions\CruceSanciones::class, $mock);

    $this->actingAs($user)->postJson("/api/subjects/{$subject->id}/sanciones/cruzar")
        ->assertStatus(503)
        ->assertJsonPath('mensaje', fn ($m) => str_contains($m, 'índice de búsqueda no está disponible'));
});

it('un error de codigo al cruzar (no de Meilisearch) no se disfraza de indice caido', function () {
    $tenant = Tenant::create();
    tenancy()->initialize($tenant);
    $subject = Subject::factory()->create();
    tenancy()->end();
    $user = usuarioSanciones($tenant, 'analista');

    $mock = Mockery::mock(\App\Services\Sanctions\CruceSanciones::class);
    $mock->shouldReceive('cruzar')->once()->andThrow(new \TypeError('bug real'));
    $this->app->instance(\App\Services\Sanctions\CruceSanciones::class, $mock);

    $this->actingAs($user)->postJson("/api/subjects/{$subject->id}/sanciones/cruzar")->assertStatus(500);
});

it('el endpoint cruza un subject bajo demanda; lectura no puede', function () {
    $tenant = Tenant::create();
    $nombre = 'Qazwsx Edcrfv Tgbyhn';
    SanctionEntry::factory()->create(['nombre' => $nombre]);
    esperarSancionIndexada($nombre);
    tenancy()->initialize($tenant);
    $subject = Subject::factory()->create(['nombre_canonico' => $nombre]);
    tenancy()->end();

    $this->actingAs(usuarioSanciones($tenant, 'lectura'))->postJson("/api/subjects/{$subject->id}/sanciones/cruzar")->assertForbidden();

    $this->actingAs(usuarioSanciones($tenant, 'analista'))->postJson("/api/subjects/{$subject->id}/sanciones/cruzar")
        ->assertOk()->assertJsonPath('hallazgos_nuevos', 1);
});

it('lista los hallazgos del tenant con la entrada de la lista, sin mezclar tenants', function () {
    $a = Tenant::create();
    $b = Tenant::create();
    $entrada = SanctionEntry::factory()->create(['sanction_list_id' => SanctionList::firstOrCreate(['codigo' => 'ofac_sdn'])->id, 'nombre' => 'Lista Nombre', 'programa' => 'SDGT']);

    tenancy()->initialize($a);
    hallazgoSancion(['estado' => 'pendiente'], $entrada);
    tenancy()->end();
    tenancy()->initialize($b);
    hallazgoSancion();
    tenancy()->end();

    $r = $this->actingAs(usuarioSanciones($a, 'lectura'))->getJson('/api/sanciones')->assertOk();

    expect($r->json('data'))->toHaveCount(1)
        ->and($r->json('data.0.entrada.nombre'))->toBe('Lista Nombre')
        ->and($r->json('data.0.entrada.programa'))->toBe('SDGT')
        ->and($r->json('data.0.subject.nombre_canonico'))->not->toBeNull();
});

it('filtra por estado y por subject', function () {
    $tenant = Tenant::create();
    tenancy()->initialize($tenant);
    $s1 = Subject::factory()->create();
    $s2 = Subject::factory()->create();
    hallazgoSancion(['subject_id' => $s1->id, 'estado' => 'pendiente']);
    hallazgoSancion(['subject_id' => $s2->id, 'estado' => 'confirmado']);
    tenancy()->end();
    $user = usuarioSanciones($tenant, 'analista');

    expect($this->actingAs($user)->getJson('/api/sanciones?estado=pendiente')->json('data'))->toHaveCount(1);
    expect($this->actingAs($user)->getJson('/api/sanciones?estado=todos')->json('data'))->toHaveCount(2);
    expect($this->actingAs($user)->getJson("/api/sanciones?estado=todos&subject_id={$s2->id}")->json('data'))->toHaveCount(1);
});

it('solo oficial_cumplimiento y admin resuelven; queda auditado con quien y cuando', function () {
    $tenant = Tenant::create();
    tenancy()->initialize($tenant);
    $hallazgo = hallazgoSancion(['estado' => 'pendiente']);
    tenancy()->end();

    foreach (['analista', 'lectura'] as $rol) {
        $this->actingAs(usuarioSanciones($tenant, $rol))->postJson("/api/sanciones/{$hallazgo->id}/resolver", ['estado' => 'confirmado'])->assertForbidden();
    }

    $oficial = usuarioSanciones($tenant, 'oficial_cumplimiento');
    $this->actingAs($oficial)->postJson("/api/sanciones/{$hallazgo->id}/resolver", ['estado' => 'confirmado'])
        ->assertOk()->assertJsonPath('estado', 'confirmado');

    tenancy()->initialize($tenant);
    $hallazgo->refresh();
    expect($hallazgo->resuelto_por)->toBe($oficial->id)->and($hallazgo->resuelto_en)->not->toBeNull();
    tenancy()->end();
    expect(Activity::where('subject_type', SanctionMatch::class)->where('subject_id', $hallazgo->id)->where('event', 'updated')->exists())->toBeTrue();
});

it('rechaza resolver un hallazgo ya resuelto, un estado invalido y pendiente', function () {
    $tenant = Tenant::create();
    tenancy()->initialize($tenant);
    $resuelto = hallazgoSancion(['estado' => 'confirmado']);
    $pendiente = hallazgoSancion(['estado' => 'pendiente']);
    tenancy()->end();
    $oficial = usuarioSanciones($tenant, 'oficial_cumplimiento');

    $this->actingAs($oficial)->postJson("/api/sanciones/{$resuelto->id}/resolver", ['estado' => 'falso_positivo'])->assertUnprocessable();
    $this->actingAs($oficial)->postJson("/api/sanciones/{$pendiente->id}/resolver", ['estado' => 'pendiente'])->assertUnprocessable();
    $this->actingAs($oficial)->postJson("/api/sanciones/{$pendiente->id}/resolver", ['estado' => 'otro'])->assertUnprocessable();
});

it('no se puede resolver un hallazgo de otro tenant', function () {
    $a = Tenant::create();
    $b = Tenant::create();
    tenancy()->initialize($a);
    $hallazgo = hallazgoSancion(['estado' => 'pendiente']);
    tenancy()->end();

    $this->actingAs(usuarioSanciones($b, 'admin'))->postJson("/api/sanciones/{$hallazgo->id}/resolver", ['estado' => 'confirmado'])->assertNotFound();
});
