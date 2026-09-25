<?php

declare(strict_types=1);

use App\Enums\EstadoSearchResult;
use App\Enums\GapMotivo;
use App\Jobs\RunTagSearchJob;
use App\Models\SearchResult;
use App\Models\SearchRun;
use App\Models\Source;
use Illuminate\Support\Facades\Http;
use Stancl\Tenancy\Database\Models\Tenant;

/**
 * Busqueda por tags (sesion posterior a la 3.7): a diferencia de
 * RunSubjectSearchJob, aqui no hay ningun Subject del cual derivar el
 * tenant_id (DerivesTenantFromSubject no hace nada si subject_id es
 * null) - handle() debe correr con tenancy() ya inicializada, como
 * pasaria de verdad via QueueTenancyBootstrapper cuando Horizon procesa
 * el job real. Por eso, a diferencia de RunSubjectSearchJobTest, aqui SI
 * hace falta envolver el ->handle() en tenancy()->initialize()/end().
 */
it('construye la query con los tags + restriccion de dominios, sin subject, y guarda los search_results', function () {
    $tenant = Tenant::create();
    $source = Source::factory()->create(['tipo' => 'brave']);

    Http::fake(['api.search.brave.com/*' => Http::response([
        'web' => ['results' => [
            ['url' => 'https://medio.example/nota-hurto', 'title' => 'Nota hurto'],
        ]],
    ], 200)]);

    tenancy()->initialize($tenant);
    $searchRun = (new RunTagSearchJob(['hurto', 'estafa'], $source->id))->handle();

    expect($searchRun->subject_id)->toBeNull()
        ->and($searchRun->tenant_id)->toBe($tenant->id)
        ->and($searchRun->tags)->toBe(['estafa', 'hurto'])
        ->and($searchRun->query)->toContain('"hurto"')->toContain('"estafa"')->toContain('site:laprensagrafica.com');

    $resultado = SearchResult::where('url', 'https://medio.example/nota-hurto')->sole();
    expect($resultado->subject_id)->toBeNull()
        ->and($resultado->tenant_id)->toBe($tenant->id)
        ->and($resultado->estado)->toBe(EstadoSearchResult::Nuevo);
    tenancy()->end();
});

it('pasa dias_atras al search_run y a cada search_result creado', function () {
    $tenant = Tenant::create();
    $source = Source::factory()->create(['tipo' => 'brave']);

    Http::fake(['api.search.brave.com/*' => Http::response([
        'web' => ['results' => [['url' => 'https://medio.example/nota-a']]],
    ], 200)]);

    tenancy()->initialize($tenant);
    $searchRun = (new RunTagSearchJob(['hurto'], $source->id, diasAtras: 7))->handle();

    expect($searchRun->dias_atras)->toBe(7)
        ->and(SearchResult::sole()->dias_atras)->toBe(7);
    tenancy()->end();
});

it('marca GAP fuera_de_ventana de una vez si Brave ya trae una fecha vieja, respetando dias_atras', function () {
    $tenant = Tenant::create();
    $source = Source::factory()->create(['tipo' => 'brave']);

    Http::fake(['api.search.brave.com/*' => Http::response([
        'web' => ['results' => [
            ['url' => 'https://medio.example/nota-vieja', 'page_age' => Carbon\Carbon::now()->subDays(10)->toIso8601String()],
        ]],
    ], 200)]);

    // dias_atras=5: una nota de hace 10 dias ya queda fuera, aunque
    // estuviera dentro del default global de 30.
    tenancy()->initialize($tenant);
    (new RunTagSearchJob(['hurto'], $source->id, diasAtras: 5))->handle();

    $resultado = SearchResult::sole();
    expect($resultado->estado)->toBe(EstadoSearchResult::Gap)
        ->and($resultado->gap_motivo)->toBe(GapMotivo::FueraDeVentana);
    tenancy()->end();
});

it('es idempotente: la misma combinacion de tags el mismo dia no repite la busqueda', function () {
    $tenant = Tenant::create();
    $source = Source::factory()->create(['tipo' => 'brave']);

    Http::fake(['api.search.brave.com/*' => Http::response([
        'web' => ['results' => [['url' => 'https://medio.example/nota-a']]],
    ], 200)]);

    tenancy()->initialize($tenant);
    $primero = (new RunTagSearchJob(['hurto', 'estafa'], $source->id))->handle();
    $segundo = (new RunTagSearchJob(['estafa', 'hurto'], $source->id))->handle();
    tenancy()->end();

    expect($segundo->id)->toBe($primero->id);
    Http::assertSentCount(1);
});

it('no es idempotente si los tags son distintos, aunque sea el mismo dia', function () {
    $tenant = Tenant::create();
    $source = Source::factory()->create(['tipo' => 'brave']);

    Http::fake(['api.search.brave.com/*' => Http::response([
        'web' => ['results' => []],
    ], 200)]);

    tenancy()->initialize($tenant);
    (new RunTagSearchJob(['hurto'], $source->id))->handle();
    (new RunTagSearchJob(['estafa'], $source->id))->handle();

    expect(SearchRun::count())->toBe(2);
    tenancy()->end();

    Http::assertSentCount(2);
});

it('envia a Brave freshness con dias_atras de la busqueda, o ARTICLE_WINDOW_DAYS si no trae', function () {
    config(['vera.article_window_days' => 60]);
    Carbon\Carbon::setTestNow('2026-09-25 10:00:00');

    $tenant = Tenant::create();
    $source = Source::factory()->create(['tipo' => 'brave']);
    Http::fake(['api.search.brave.com/*' => Http::response(['web' => ['results' => []]], 200)]);

    tenancy()->initialize($tenant);
    (new RunTagSearchJob(['hurto'], $source->id, diasAtras: 7))->handle();
    (new RunTagSearchJob(['estafa'], $source->id))->handle();
    tenancy()->end();

    Http::assertSent(fn ($request) => $request['freshness'] === '2026-09-18to2026-09-25');
    Http::assertSent(fn ($request) => $request['freshness'] === '2026-07-27to2026-09-25');
    Carbon\Carbon::setTestNow();
});
