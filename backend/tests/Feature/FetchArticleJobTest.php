<?php

declare(strict_types=1);

use App\Enums\EstadoSearchResult;
use App\Enums\GapMotivo;
use App\Jobs\ExtractEntitiesJob;
use App\Jobs\FetchArticleJob;
use App\Jobs\MatchMentionsJob;
use App\Models\Article;
use App\Models\Mention;
use App\Models\SearchResult;
use App\Models\Subject;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Stancl\Tenancy\Database\Models\Tenant;

function htmlConMeta(string $fecha): string
{
    return <<<HTML
    <html><head>
        <title>Titulo de prueba</title>
        <meta property="article:published_time" content="{$fecha}">
    </head><body>contenido</body></html>
    HTML;
}

function htmlConJsonLd(string $fecha): string
{
    return <<<HTML
    <html><head>
        <title>Titulo via JSON-LD</title>
        <script type="application/ld+json">{"@type":"NewsArticle","datePublished":"{$fecha}"}</script>
    </head><body>contenido</body></html>
    HTML;
}

function htmlConTimeTag(string $fecha): string
{
    return <<<HTML
    <html><head><title>Titulo via time tag</title></head>
    <body><time datetime="{$fecha}">hoy</time></body></html>
    HTML;
}

/**
 * search_results usa BelongsToTenant/DerivesTenantFromSubject - hace
 * falta un tenant+subject reales para crear uno, igual que MentionMatch.
 */
function crearSearchResultDePrueba(string $url): SearchResult
{
    $tenant = Tenant::create();
    tenancy()->initialize($tenant);
    $subject = Subject::factory()->create();
    $searchResult = SearchResult::factory()->for($subject, 'subject')->create(['url' => $url]);
    tenancy()->end();

    return $searchResult;
}

beforeEach(function () {
    Storage::fake();
    // FetchArticleJob encola ExtractEntitiesJob al crear un Article; estos
    // tests son sobre FetchArticleJob en aislamiento, no sobre el pipeline
    // completo (eso lo cubre su propio test), asi que se evita que corra.
    Bus::fake();
});

it('extrae la fecha desde meta article:published_time y guarda el articulo', function () {
    $html = htmlConMeta(now()->subDays(2)->toIso8601String());
    $searchResult = crearSearchResultDePrueba('https://medio.example/nota-1');
    Http::fake(['*' => Http::response($html, 200, ['Content-Type' => 'text/html'])]);

    (new FetchArticleJob($searchResult->id))->handle();

    $article = Article::sole();
    expect($article->titulo)->toBe('Titulo de prueba')
        ->and($article->medio)->toBe('medio.example')
        ->and($article->estado_extraccion)->toBe('pendiente')
        ->and($article->fecha_publicacion->isToday())->toBeFalse();

    Storage::assertExists($article->evidence_path);

    tenancy()->initialize($searchResult->tenant);
    expect($searchResult->refresh()->article_id)->toBe($article->id);
    tenancy()->end();

    Bus::assertDispatched(ExtractEntitiesJob::class);
});

it('usa JSON-LD si no hay meta article:published_time', function () {
    $html = htmlConJsonLd(now()->subDay()->toIso8601String());
    $searchResult = crearSearchResultDePrueba('https://medio.example/nota-2');
    Http::fake(['*' => Http::response($html, 200, ['Content-Type' => 'text/html'])]);

    (new FetchArticleJob($searchResult->id))->handle();

    expect(Article::sole()->fecha_publicacion)->not->toBeNull();
});

it('usa el tag time[datetime] si no hay meta ni JSON-LD', function () {
    $html = htmlConTimeTag(now()->subDay()->toIso8601String());
    $searchResult = crearSearchResultDePrueba('https://medio.example/nota-3');
    Http::fake(['*' => Http::response($html, 200, ['Content-Type' => 'text/html'])]);

    (new FetchArticleJob($searchResult->id))->handle();

    expect(Article::sole()->fecha_publicacion)->not->toBeNull();
});

it('reusa un articulo ya existente para la misma url en vez de descargarlo de nuevo', function () {
    $url = 'https://medio.example/nota-repetida';
    $article = Article::factory()->create(['url' => $url, 'estado_extraccion' => 'completado']);
    Mention::factory()->create(['article_id' => $article->id]);
    $searchResult = crearSearchResultDePrueba($url);

    Http::fake(['*' => Http::response('no deberia llegar aqui', 200)]);

    (new FetchArticleJob($searchResult->id))->handle();

    Http::assertNothingSent();

    tenancy()->initialize($searchResult->tenant);
    $searchResult->refresh();
    expect($searchResult->article_id)->toBe($article->id)
        ->and($searchResult->estado)->toBe(EstadoSearchResult::Extraido);
    tenancy()->end();

    Bus::assertDispatched(MatchMentionsJob::class);
});

it('marca sin_menciones al reusar un articulo ya extraido sin personas', function () {
    $url = 'https://medio.example/nota-sin-menciones';
    $article = Article::factory()->create(['url' => $url, 'estado_extraccion' => 'completado']);
    $searchResult = crearSearchResultDePrueba($url);

    (new FetchArticleJob($searchResult->id))->handle();

    tenancy()->initialize($searchResult->tenant);
    expect($searchResult->refresh()->estado)->toBe(EstadoSearchResult::SinMenciones);
    tenancy()->end();
});

it('marca GAP http_403 cuando el sitio devuelve 403 (ej. Cloudflare)', function () {
    $searchResult = crearSearchResultDePrueba('https://medio.example/bloqueado');
    Http::fake(['*' => Http::response('bloqueado', 403)]);

    (new FetchArticleJob($searchResult->id))->handle();

    tenancy()->initialize($searchResult->tenant);
    expect($searchResult->refresh()->estado)->toBe(EstadoSearchResult::Gap)
        ->and($searchResult->gap_motivo)->toBe(GapMotivo::Http403)
        ->and($searchResult->http_status)->toBe(403);
    tenancy()->end();

    expect(Article::count())->toBe(0);
});

it('marca GAP http_error para otros codigos de error HTTP', function () {
    $searchResult = crearSearchResultDePrueba('https://medio.example/error-500');
    Http::fake(['*' => Http::response('error', 500)]);

    (new FetchArticleJob($searchResult->id))->handle();

    tenancy()->initialize($searchResult->tenant);
    expect($searchResult->refresh()->gap_motivo)->toBe(GapMotivo::HttpError);
    tenancy()->end();
});

it('marca GAP timeout cuando la conexion falla', function () {
    $searchResult = crearSearchResultDePrueba('https://medio.example/timeout');
    Http::fake(function () {
        throw new ConnectionException('timed out');
    });

    (new FetchArticleJob($searchResult->id))->handle();

    tenancy()->initialize($searchResult->tenant);
    expect($searchResult->refresh()->gap_motivo)->toBe(GapMotivo::Timeout);
    tenancy()->end();
});

it('marca GAP no_html cuando el content-type no es HTML (ej. un PDF)', function () {
    $searchResult = crearSearchResultDePrueba('https://medio.example/reporte.pdf');
    Http::fake(['*' => Http::response('%PDF-1.4 contenido binario', 200, ['Content-Type' => 'application/pdf'])]);

    (new FetchArticleJob($searchResult->id))->handle();

    tenancy()->initialize($searchResult->tenant);
    expect($searchResult->refresh()->gap_motivo)->toBe(GapMotivo::NoHtml);
    tenancy()->end();

    expect(Article::count())->toBe(0);
});

it('marca GAP sin_contenido cuando el HTML no tiene texto util', function () {
    $searchResult = crearSearchResultDePrueba('https://medio.example/vacio');
    Http::fake(['*' => Http::response('<html><body><script>1+1</script></body></html>', 200, ['Content-Type' => 'text/html'])]);

    (new FetchArticleJob($searchResult->id))->handle();

    tenancy()->initialize($searchResult->tenant);
    expect($searchResult->refresh()->gap_motivo)->toBe(GapMotivo::SinContenido);
    tenancy()->end();
});

it('marca GAP fuera_de_ventana si la fecha de publicacion esta fuera de la ventana configurada', function () {
    config(['vera.article_window_days' => 30]);
    $html = htmlConMeta(now()->subDays(90)->toIso8601String());
    $searchResult = crearSearchResultDePrueba('https://medio.example/nota-vieja');
    Http::fake(['*' => Http::response($html, 200, ['Content-Type' => 'text/html'])]);

    (new FetchArticleJob($searchResult->id))->handle();

    tenancy()->initialize($searchResult->tenant);
    expect($searchResult->refresh()->gap_motivo)->toBe(GapMotivo::FueraDeVentana);
    tenancy()->end();

    expect(Article::count())->toBe(0);
});

it('conserva el articulo si no se pudo determinar la fecha de publicacion', function () {
    $html = '<html><head><title>Sin fecha</title></head><body>contenido real de la nota</body></html>';
    $searchResult = crearSearchResultDePrueba('https://medio.example/sin-fecha');
    Http::fake(['*' => Http::response($html, 200, ['Content-Type' => 'text/html'])]);

    (new FetchArticleJob($searchResult->id))->handle();

    $article = Article::sole();
    expect($article->fecha_publicacion)->toBeNull();
});
