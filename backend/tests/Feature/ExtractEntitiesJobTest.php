<?php

declare(strict_types=1);

use App\Enums\EstadoSearchResult;
use App\Jobs\ExtractEntitiesJob;
use App\Jobs\MatchMentionsJob;
use App\Models\Article;
use App\Models\Extraction;
use App\Models\Mention;
use App\Models\SearchResult;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Stancl\Tenancy\Database\Models\Tenant;

function fakeArticleWithEvidence(): Article
{
    Storage::fake();
    $path = 'articles/test.html';
    Storage::put($path, '<html><body><h1>Nota</h1><p>Contenido de prueba</p></body></html>');

    return Article::factory()->create(['evidence_path' => $path, 'estado_extraccion' => 'pendiente']);
}

function anthropicResponse(array $resultado, int $tokensIn = 500, int $tokensOut = 150): array
{
    return [
        'content' => [
            ['type' => 'text', 'text' => json_encode($resultado)],
        ],
        'usage' => ['input_tokens' => $tokensIn, 'output_tokens' => $tokensOut],
    ];
}

it('extrae mentions de un articulo cuando la confianza es alta y no escala', function () {
    Bus::fake();

    $article = fakeArticleWithEvidence();

    Http::fake(['api.anthropic.com/*' => Http::response(anthropicResponse([
        'personas' => [
            ['nombre' => 'Juan Perez', 'rol' => 'imputado', 'delitos' => ['hurto'], 'institucion_relacionada' => 'PNC', 'confianza' => 0.9],
        ],
        'fecha_hecho' => '2026-08-01',
        'resumen' => 'Resumen de prueba.',
        'confianza_global' => 0.9,
    ]), 200)]);

    (new ExtractEntitiesJob($article->id))->handle(app(App\Services\Extraction\AnthropicClient::class));

    expect(Extraction::count())->toBe(1)
        ->and(Extraction::first()->modelo)->toBe(config('services.anthropic.model_fast'))
        ->and(Mention::count())->toBe(1)
        ->and(Mention::first()->nombre_extraido)->toBe('Juan Perez');

    expect($article->refresh()->estado_extraccion)->toBe('completado');

    Bus::assertDispatched(MatchMentionsJob::class, 1);
    Http::assertSentCount(1);
});

it('es idempotente: no vuelve a llamar a Anthropic si el articulo ya no esta pendiente', function () {
    Bus::fake();
    $article = fakeArticleWithEvidence();

    Http::fake(['api.anthropic.com/*' => Http::response(anthropicResponse([
        'personas' => [
            ['nombre' => 'Juan Perez', 'rol' => 'imputado', 'delitos' => [], 'institucion_relacionada' => null, 'confianza' => 0.9],
        ],
        'fecha_hecho' => null,
        'resumen' => 'Resumen.',
        'confianza_global' => 0.9,
    ]), 200)]);

    (new ExtractEntitiesJob($article->id))->handle(app(App\Services\Extraction\AnthropicClient::class));
    (new ExtractEntitiesJob($article->id))->handle(app(App\Services\Extraction\AnthropicClient::class));

    Http::assertSentCount(1);
    expect(Extraction::count())->toBe(1)
        ->and(Mention::count())->toBe(1);
});

it('failed() marca el articulo como fallido en vez de dejarlo pendiente para siempre', function () {
    $article = fakeArticleWithEvidence();

    $job = new ExtractEntitiesJob($article->id);
    $job->failed(new \RuntimeException('fallo simulado'));

    expect($article->refresh()->estado_extraccion)->toBe('fallido');
});

it('escala a Sonnet cuando la confianza global es baja', function () {
    Bus::fake();
    $article = fakeArticleWithEvidence();

    $bajaConfianza = anthropicResponse([
        'personas' => [],
        'fecha_hecho' => null,
        'resumen' => 'Poco claro.',
        'confianza_global' => 0.3,
    ]);

    $altaConfianza = anthropicResponse([
        'personas' => [
            ['nombre' => 'Maria Lopez', 'rol' => 'testigo', 'delitos' => [], 'institucion_relacionada' => null, 'confianza' => 0.85],
        ],
        'fecha_hecho' => null,
        'resumen' => 'Ahora si claro.',
        'confianza_global' => 0.85,
    ]);

    Http::fake(['api.anthropic.com/*' => Http::sequence()
        ->push($bajaConfianza, 200)
        ->push($altaConfianza, 200)]);

    (new ExtractEntitiesJob($article->id))->handle(app(App\Services\Extraction\AnthropicClient::class));

    expect(Extraction::count())->toBe(2)
        ->and(Extraction::latest('id')->first()->modelo)->toBe(config('services.anthropic.model_escalation'))
        ->and(Mention::count())->toBe(1)
        ->and(Mention::first()->nombre_extraido)->toBe('Maria Lopez');
});

it('escala cuando hay mas de 3 personas con roles distintos entre si', function () {
    Bus::fake();
    $article = fakeArticleWithEvidence();

    $muchasPersonas = anthropicResponse([
        'personas' => [
            ['nombre' => 'Persona Uno', 'rol' => 'imputado', 'delitos' => [], 'institucion_relacionada' => null, 'confianza' => 0.9],
            ['nombre' => 'Persona Dos', 'rol' => 'victima', 'delitos' => [], 'institucion_relacionada' => null, 'confianza' => 0.9],
            ['nombre' => 'Persona Tres', 'rol' => 'testigo', 'delitos' => [], 'institucion_relacionada' => null, 'confianza' => 0.9],
            ['nombre' => 'Persona Cuatro', 'rol' => 'otro', 'delitos' => [], 'institucion_relacionada' => null, 'confianza' => 0.9],
        ],
        'fecha_hecho' => null,
        'resumen' => 'Varias personas.',
        'confianza_global' => 0.95,
    ]);

    $reconfirmada = anthropicResponse([
        'personas' => [
            ['nombre' => 'Persona Uno', 'rol' => 'imputado', 'delitos' => [], 'institucion_relacionada' => null, 'confianza' => 0.9],
        ],
        'fecha_hecho' => null,
        'resumen' => 'Revisado.',
        'confianza_global' => 0.95,
    ]);

    Http::fake(['api.anthropic.com/*' => Http::sequence()
        ->push($muchasPersonas, 200)
        ->push($reconfirmada, 200)]);

    (new ExtractEntitiesJob($article->id))->handle(app(App\Services\Extraction\AnthropicClient::class));

    Http::assertSentCount(2);
    expect(Extraction::count())->toBe(2);
});

it('marca el search_result como extraido cuando la extraccion encuentra personas', function () {
    Bus::fake();
    $article = fakeArticleWithEvidence();

    $tenant = Tenant::create();
    tenancy()->initialize($tenant);
    $subject = App\Models\Subject::factory()->create();
    $searchResult = SearchResult::factory()->for($subject, 'subject')->create();
    tenancy()->end();

    Http::fake(['api.anthropic.com/*' => Http::response(anthropicResponse([
        'personas' => [
            ['nombre' => 'Juan Perez', 'rol' => 'imputado', 'delitos' => ['hurto'], 'institucion_relacionada' => null, 'confianza' => 0.9],
        ],
        'fecha_hecho' => null,
        'resumen' => 'Resumen.',
        'confianza_global' => 0.9,
    ]), 200)]);

    (new ExtractEntitiesJob($article->id, $searchResult->id))
        ->handle(app(App\Services\Extraction\AnthropicClient::class));

    tenancy()->initialize($tenant);
    expect($searchResult->refresh()->estado)->toBe(EstadoSearchResult::Extraido);
    expect(Mention::first()->search_result_id)->toBe($searchResult->id);
    tenancy()->end();
});

it('marca el search_result como sin_menciones cuando la IA no encuentra personas', function () {
    $article = fakeArticleWithEvidence();

    $tenant = Tenant::create();
    tenancy()->initialize($tenant);
    $subject = App\Models\Subject::factory()->create();
    $searchResult = SearchResult::factory()->for($subject, 'subject')->create();
    tenancy()->end();

    Http::fake(['api.anthropic.com/*' => Http::response(anthropicResponse([
        'personas' => [],
        'fecha_hecho' => null,
        'resumen' => 'Nada relevante.',
        'confianza_global' => 0.9,
    ]), 200)]);

    (new ExtractEntitiesJob($article->id, $searchResult->id))
        ->handle(app(App\Services\Extraction\AnthropicClient::class));

    tenancy()->initialize($tenant);
    expect($searchResult->refresh()->estado)->toBe(EstadoSearchResult::SinMenciones);
    tenancy()->end();
});

it('lanza excepcion si Claude devuelve un JSON que no cumple el contrato', function () {
    $article = fakeArticleWithEvidence();

    Http::fake(['api.anthropic.com/*' => Http::response(anthropicResponse([
        'personas' => 'esto deberia ser un array, no un string',
        'confianza_global' => 0.9,
    ]), 200)]);

    $lanzada = false;

    try {
        (new ExtractEntitiesJob($article->id))->handle(app(App\Services\Extraction\AnthropicClient::class));
    } catch (\Throwable) {
        $lanzada = true;
    }

    expect($lanzada)->toBeTrue();
    expect(Mention::count())->toBe(0);
});
