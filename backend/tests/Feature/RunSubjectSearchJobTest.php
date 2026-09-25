<?php

declare(strict_types=1);

use App\Enums\EstadoSearchResult;
use App\Enums\GapMotivo;
use App\Jobs\FetchArticleJob;
use App\Jobs\RunSubjectSearchJob;
use App\Models\SearchResult;
use App\Models\SearchRun;
use App\Models\Source;
use App\Models\Subject;
use App\Models\SubjectAlias;
use App\Services\Search\LimiteDeQuery;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Stancl\Tenancy\Database\Models\Tenant;

it('construye la query con nombre canonico + aliases, guarda el search_run y un search_result por resultado (sin encolar FetchArticleJob)', function () {
    Bus::fake();

    $tenant = Tenant::create();
    tenancy()->initialize($tenant);
    $subject = Subject::factory()->create(['nombre_canonico' => 'Juan Perez']);
    SubjectAlias::factory()->for($subject, 'subject')->create(['nombre' => 'Juanito Perez']);
    tenancy()->end();

    $source = Source::factory()->create(['tipo' => 'cse']);

    Http::fake(['www.googleapis.com/*' => Http::response([
        'items' => [
            ['link' => 'https://medio.example/nota-a', 'title' => 'Nota A', 'snippet' => 'resumen a'],
            ['link' => 'https://medio.example/nota-b', 'title' => 'Nota B', 'snippet' => 'resumen b'],
        ],
    ], 200)]);

    $searchRun = (new RunSubjectSearchJob($subject->id, $source->id))->handle();

    expect($searchRun->query)->toContain('"Juan Perez"')->toContain('"Juanito Perez"');

    tenancy()->initialize($tenant);
    expect($searchRun->tenant_id)->toBe($tenant->id);
    expect(SearchRun::count())->toBe(1);

    $resultados = SearchResult::where('subject_id', $subject->id)->get();
    expect($resultados)->toHaveCount(2)
        ->and($resultados->pluck('url')->all())->toBe([
            'https://medio.example/nota-a',
            'https://medio.example/nota-b',
        ])
        ->and($resultados->first()->titulo)->toBe('Nota A')
        ->and($resultados->first()->estado)->toBe(EstadoSearchResult::Nuevo);
    tenancy()->end();

    // Seccion 3.7: nada se descarga automatico, solo se lista.
    Bus::assertNotDispatched(FetchArticleJob::class);
});

it('restringe la query a los medios salvadorenses por default cuando la source no trae su propia lista de dominios', function () {
    $tenant = Tenant::create();
    tenancy()->initialize($tenant);
    $subject = Subject::factory()->create(['nombre_canonico' => 'Juan Perez']);
    tenancy()->end();

    // Sin 'dominios' en config (caso real: asi quedo la Source de Brave
    // que crea vera:demo) - hallazgo real de la sesion 2026-09-24: sin
    // esta restriccion, Brave Search busca en toda la web y devuelve
    // resultados irrelevantes (ej. un articulo de Wikipedia).
    $source = Source::factory()->create(['tipo' => 'brave', 'config' => []]);

    Http::fake(['api.search.brave.com/*' => Http::response(['web' => ['results' => []]], 200)]);

    $searchRun = (new RunSubjectSearchJob($subject->id, $source->id))->handle();

    expect($searchRun->query)
        ->toContain('"Juan Perez"')
        ->toContain('site:laprensagrafica.com')
        ->toContain('site:elsalvador.com');
});

it('usa la lista de dominios propia de la source cuando config la trae, en vez del default', function () {
    $tenant = Tenant::create();
    tenancy()->initialize($tenant);
    $subject = Subject::factory()->create(['nombre_canonico' => 'Juan Perez']);
    tenancy()->end();

    $source = Source::factory()->create([
        'tipo' => 'brave',
        'config' => ['dominios' => ['fiscalia.gob.sv']],
    ]);

    Http::fake(['api.search.brave.com/*' => Http::response(['web' => ['results' => []]], 200)]);

    $searchRun = (new RunSubjectSearchJob($subject->id, $source->id))->handle();

    expect($searchRun->query)
        ->toContain('site:fiscalia.gob.sv')
        ->not->toContain('laprensagrafica.com');
});

it('es idempotente: correr el job dos veces el mismo dia no repite la busqueda ni duplica el search_run', function () {
    Bus::fake();

    $tenant = Tenant::create();
    tenancy()->initialize($tenant);
    $subject = Subject::factory()->create();
    tenancy()->end();

    $source = Source::factory()->create(['tipo' => 'cse']);

    Http::fake(['www.googleapis.com/*' => Http::response(['items' => [
        ['link' => 'https://medio.example/nota-a'],
    ]], 200)]);

    $primero = (new RunSubjectSearchJob($subject->id, $source->id))->handle();
    $segundo = (new RunSubjectSearchJob($subject->id, $source->id))->handle();

    expect($segundo->id)->toBe($primero->id);

    tenancy()->initialize($tenant);
    expect(SearchRun::count())->toBe(1);
    tenancy()->end();

    Http::assertSentCount(1);
});

it('no duplica ni resetea un search_result cuando la misma url vuelve a salir en otro dia', function () {
    $tenant = Tenant::create();
    tenancy()->initialize($tenant);
    $subject = Subject::factory()->create();
    tenancy()->end();

    $source = Source::factory()->create(['tipo' => 'cse']);

    Http::fake(['www.googleapis.com/*' => Http::response(['items' => [
        ['link' => 'https://medio.example/nota-a', 'title' => 'Nota A'],
    ]], 200)]);

    Carbon\Carbon::setTestNow('2026-09-24 12:00:00');
    (new RunSubjectSearchJob($subject->id, $source->id))->handle();

    tenancy()->initialize($tenant);
    $resultado = SearchResult::where('subject_id', $subject->id)->sole();
    $resultado->forceFill(['estado' => EstadoSearchResult::Extraido])->save();
    tenancy()->end();

    // Otro dia -> ya no es idempotente a nivel de SearchRun, corre de
    // nuevo y Brave vuelve a devolver la misma URL.
    Carbon\Carbon::setTestNow('2026-09-25 12:00:00');
    (new RunSubjectSearchJob($subject->id, $source->id))->handle();

    tenancy()->initialize($tenant);
    expect(SearchResult::where('subject_id', $subject->id)->count())->toBe(1);
    // El estado que ya tenia (procesado por el analista) no se pisa.
    expect($resultado->refresh()->estado)->toBe(EstadoSearchResult::Extraido);
    tenancy()->end();

    Carbon\Carbon::setTestNow();
});

it('rutea una source tipo brave a BraveSearchAdapter (fuente activa por default desde 2026-09-24)', function () {
    $tenant = Tenant::create();
    tenancy()->initialize($tenant);
    $subject = Subject::factory()->create(['nombre_canonico' => 'Juan Perez']);
    tenancy()->end();

    $source = Source::factory()->create(['tipo' => 'brave']);

    Http::fake(['api.search.brave.com/*' => Http::response([
        'web' => ['results' => [
            ['url' => 'https://medio.example/nota-brave', 'title' => 'Nota Brave'],
        ]],
    ], 200)]);

    (new RunSubjectSearchJob($subject->id, $source->id))->handle();

    tenancy()->initialize($tenant);
    expect(SearchResult::where('subject_id', $subject->id)->sole()->url)
        ->toBe('https://medio.example/nota-brave');
    tenancy()->end();
});

it('marca GAP fuera_de_ventana de una vez si Brave ya trae una fecha vieja, sin esperar a que el analista pida extraer', function () {
    $tenant = Tenant::create();
    tenancy()->initialize($tenant);
    $subject = Subject::factory()->create(['nombre_canonico' => 'Juan Perez']);
    tenancy()->end();

    $source = Source::factory()->create(['tipo' => 'brave']);

    Http::fake(['api.search.brave.com/*' => Http::response([
        'web' => ['results' => [
            // Bug real reportado por el usuario (2026-09-24, /subjects/5):
            // Brave devuelve noticias de hace anos y se quedaban en
            // 'nuevo' indefinidamente porque el unico chequeo de ventana
            // vivia en FetchArticleJob, que solo corre si alguien pide
            // extraer ese resultado en concreto.
            ['url' => 'https://medio.example/nota-vieja', 'title' => 'Nota vieja', 'page_age' => '2021-03-26T00:00:00'],
            ['url' => 'https://medio.example/nota-reciente', 'title' => 'Nota reciente', 'page_age' => Carbon\Carbon::now()->subDays(2)->toIso8601String()],
            ['url' => 'https://medio.example/nota-sin-fecha', 'title' => 'Nota sin fecha'],
        ]],
    ], 200)]);

    (new RunSubjectSearchJob($subject->id, $source->id))->handle();

    tenancy()->initialize($tenant);
    $vieja = SearchResult::where('url', 'https://medio.example/nota-vieja')->sole();
    $reciente = SearchResult::where('url', 'https://medio.example/nota-reciente')->sole();
    $sinFecha = SearchResult::where('url', 'https://medio.example/nota-sin-fecha')->sole();

    expect($vieja->estado)->toBe(EstadoSearchResult::Gap)
        ->and($vieja->gap_motivo)->toBe(GapMotivo::FueraDeVentana)
        ->and($reciente->estado)->toBe(EstadoSearchResult::Nuevo)
        // Sin fecha de Brave no se puede saber si esta fuera de ventana -
        // se deja pasar, el chequeo real (con la fecha del articulo) lo
        // hace FetchArticleJob si el analista pide extraerlo.
        ->and($sinFecha->estado)->toBe(EstadoSearchResult::Nuevo);
    tenancy()->end();
});

it('lanza excepcion para una fuente con tipo de adaptador no implementado', function () {
    $tenant = Tenant::create();
    tenancy()->initialize($tenant);
    $subject = Subject::factory()->create();
    tenancy()->end();

    $source = Source::factory()->create(['tipo' => 'rss']);

    expect(fn () => (new RunSubjectSearchJob($subject->id, $source->id))->handle())
        ->toThrow(InvalidArgumentException::class);
});

it('envia a Brave freshness con la ventana ARTICLE_WINDOW_DAYS de la consulta puntual', function () {
    config(['vera.article_window_days' => 60]);
    Carbon\Carbon::setTestNow('2026-09-25 10:00:00');

    $tenant = Tenant::create();
    tenancy()->initialize($tenant);
    $subject = Subject::factory()->create(['nombre_canonico' => 'Juan Perez']);
    tenancy()->end();

    $source = Source::factory()->create(['tipo' => 'brave']);
    Http::fake(['api.search.brave.com/*' => Http::response(['web' => ['results' => []]], 200)]);

    (new RunSubjectSearchJob($subject->id, $source->id))->handle();

    Http::assertSent(fn ($request) => $request['freshness'] === '2026-07-27to2026-09-25');
    Carbon\Carbon::setTestNow();
});

it('guarda en metadata_query lo que Brave reporta de la query y los aliases omitidos por el limite de palabras', function () {
    $tenant = Tenant::create();
    tenancy()->initialize($tenant);
    $subject = Subject::factory()->create(['nombre_canonico' => 'Juan Carlos Perez']);
    // 12 aliases de 5 palabras: con los 7 site: por default se pasa de 75.
    foreach (range(1, 12) as $i) {
        SubjectAlias::factory()->for($subject, 'subject')->create(['nombre' => "alias{$i} uno dos tres cuatro"]);
    }
    tenancy()->end();

    $source = Source::factory()->create(['tipo' => 'brave']);
    Http::fake(['api.search.brave.com/*' => Http::response([
        'query' => ['original' => 'x', 'altered' => null, 'search_operators' => ['applied' => true]],
        'web' => ['results' => []],
    ], 200)]);

    $searchRun = (new RunSubjectSearchJob($subject->id, $source->id))->handle();

    expect(LimiteDeQuery::contarPalabras($searchRun->query))->toBeLessThanOrEqual(75)
        ->and($searchRun->query)->toContain('"Juan Carlos Perez"')->toContain('site:lanoticiasv.com')
        ->and($searchRun->metadata_query['terminos_omitidos'])->toContain('alias12 uno dos tres cuatro')
        ->and($searchRun->metadata_query['proveedor'])->toBe(['original' => 'x', 'altered' => null, 'search_operators' => ['applied' => true]]);
    Http::assertSentCount(1);
});
