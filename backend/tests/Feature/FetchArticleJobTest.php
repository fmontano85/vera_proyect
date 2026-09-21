<?php

declare(strict_types=1);

use App\Jobs\FetchArticleJob;
use App\Models\Article;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

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

beforeEach(function () {
    Storage::fake();
    // FetchArticleJob encola ExtractEntitiesJob al crear un Article; estos
    // tests son sobre FetchArticleJob en aislamiento, no sobre el pipeline
    // completo (eso lo cubre su propio test), asi que se evita que corra.
    Bus::fake();
});

it('extrae la fecha desde meta article:published_time y guarda el articulo', function () {
    $html = htmlConMeta(now()->subDays(2)->toIso8601String());
    Http::fake(['*' => Http::response($html, 200)]);

    $article = (new FetchArticleJob('https://medio.example/nota-1'))->handle();

    expect($article)->not->toBeNull()
        ->and($article->titulo)->toBe('Titulo de prueba')
        ->and($article->medio)->toBe('medio.example')
        ->and($article->estado_extraccion)->toBe('pendiente')
        ->and($article->fecha_publicacion->isToday())->toBeFalse();

    Storage::assertExists($article->evidence_path);
});

it('usa JSON-LD si no hay meta article:published_time', function () {
    $html = htmlConJsonLd(now()->subDay()->toIso8601String());
    Http::fake(['*' => Http::response($html, 200)]);

    $article = (new FetchArticleJob('https://medio.example/nota-2'))->handle();

    expect($article->fecha_publicacion)->not->toBeNull();
});

it('usa el tag time[datetime] si no hay meta ni JSON-LD', function () {
    $html = htmlConTimeTag(now()->subDay()->toIso8601String());
    Http::fake(['*' => Http::response($html, 200)]);

    $article = (new FetchArticleJob('https://medio.example/nota-3'))->handle();

    expect($article->fecha_publicacion)->not->toBeNull();
});

it('no vuelve a descargar una url que ya tiene articulo guardado', function () {
    $url = 'https://medio.example/nota-repetida';
    Article::factory()->create(['url' => $url]);

    Http::fake(['*' => Http::response('no deberia llegar aqui', 200)]);

    $result = (new FetchArticleJob($url))->handle();

    expect($result)->toBeNull();
    Http::assertNothingSent();
});

it('descarta el articulo si la fecha de publicacion esta fuera de la ventana configurada', function () {
    config(['vera.article_window_days' => 30]);
    $html = htmlConMeta(now()->subDays(90)->toIso8601String());
    Http::fake(['*' => Http::response($html, 200)]);

    $result = (new FetchArticleJob('https://medio.example/nota-vieja'))->handle();

    expect($result)->toBeNull();
    expect(Article::count())->toBe(0);
});

it('conserva el articulo si no se pudo determinar la fecha de publicacion', function () {
    $html = '<html><head><title>Sin fecha</title></head><body>contenido</body></html>';
    Http::fake(['*' => Http::response($html, 200)]);

    $article = (new FetchArticleJob('https://medio.example/sin-fecha'))->handle();

    expect($article)->not->toBeNull()
        ->and($article->fecha_publicacion)->toBeNull();
});
