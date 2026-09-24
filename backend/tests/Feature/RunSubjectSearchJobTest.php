<?php

declare(strict_types=1);

use App\Enums\EstadoSearchResult;
use App\Jobs\FetchArticleJob;
use App\Jobs\RunSubjectSearchJob;
use App\Models\SearchResult;
use App\Models\SearchRun;
use App\Models\Source;
use App\Models\Subject;
use App\Models\SubjectAlias;
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
    $resultado->forceFill(['estado' => \App\Enums\EstadoSearchResult::Extraido])->save();
    tenancy()->end();

    // Otro dia -> ya no es idempotente a nivel de SearchRun, corre de
    // nuevo y Brave vuelve a devolver la misma URL.
    Carbon\Carbon::setTestNow('2026-09-25 12:00:00');
    (new RunSubjectSearchJob($subject->id, $source->id))->handle();

    tenancy()->initialize($tenant);
    expect(SearchResult::where('subject_id', $subject->id)->count())->toBe(1);
    // El estado que ya tenia (procesado por el analista) no se pisa.
    expect($resultado->refresh()->estado)->toBe(\App\Enums\EstadoSearchResult::Extraido);
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

it('lanza excepcion para una fuente con tipo de adaptador no implementado', function () {
    $tenant = Tenant::create();
    tenancy()->initialize($tenant);
    $subject = Subject::factory()->create();
    tenancy()->end();

    $source = Source::factory()->create(['tipo' => 'rss']);

    expect(fn () => (new RunSubjectSearchJob($subject->id, $source->id))->handle())
        ->toThrow(InvalidArgumentException::class);
});
