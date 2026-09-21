<?php

declare(strict_types=1);

use App\Jobs\FetchArticleJob;
use App\Jobs\RunSubjectSearchJob;
use App\Models\SearchRun;
use App\Models\Source;
use App\Models\Subject;
use App\Models\SubjectAlias;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Stancl\Tenancy\Database\Models\Tenant;

it('construye la query con nombre canonico + aliases, guarda el search_run y encola FetchArticleJob por url', function () {
    Bus::fake();

    $tenant = Tenant::create();
    tenancy()->initialize($tenant);
    $subject = Subject::factory()->create(['nombre_canonico' => 'Juan Perez']);
    SubjectAlias::factory()->for($subject, 'subject')->create(['nombre' => 'Juanito Perez']);
    tenancy()->end();

    $source = Source::factory()->create(['tipo' => 'cse']);

    Http::fake(['www.googleapis.com/*' => Http::response([
        'items' => [
            ['link' => 'https://medio.example/nota-a'],
            ['link' => 'https://medio.example/nota-b'],
        ],
    ], 200)]);

    $searchRun = (new RunSubjectSearchJob($subject->id, $source->id))->handle();

    expect($searchRun->query)->toContain('"Juan Perez"')->toContain('"Juanito Perez"')
        ->and($searchRun->resultados)->toBe(['https://medio.example/nota-a', 'https://medio.example/nota-b']);

    tenancy()->initialize($tenant);
    expect($searchRun->tenant_id)->toBe($tenant->id);
    expect(SearchRun::count())->toBe(1);
    tenancy()->end();

    Bus::assertDispatched(FetchArticleJob::class, 2);
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

it('lanza excepcion para una fuente con tipo de adaptador no implementado', function () {
    $tenant = Tenant::create();
    tenancy()->initialize($tenant);
    $subject = Subject::factory()->create();
    tenancy()->end();

    $source = Source::factory()->create(['tipo' => 'rss']);

    expect(fn () => (new RunSubjectSearchJob($subject->id, $source->id))->handle())
        ->toThrow(InvalidArgumentException::class);
});
