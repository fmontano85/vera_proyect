<?php

declare(strict_types=1);

use App\Sources\BraveSearchAdapter;
use Illuminate\Support\Facades\Http;

it('extrae las urls de una respuesta real de Brave Search', function () {
    // Forma real de la respuesta de Brave Web Search API (campos
    // irrelevantes recortados).
    $respuesta = [
        'web' => [
            'results' => [
                [
                    'title' => 'Nota 1',
                    'url' => 'https://laprensagrafica.com/nota-1',
                    'description' => 'Un <strong>resumen</strong> con HTML',
                    'meta_url' => ['hostname' => 'laprensagrafica.com'],
                    'page_age' => '2026-09-23T20:59:49',
                ],
                ['title' => 'Nota 2', 'url' => 'https://elsalvador.com/nota-2', 'description' => '...'],
            ],
        ],
    ];

    Http::fake(['api.search.brave.com/*' => Http::response($respuesta, 200)]);

    $resultado = (new BraveSearchAdapter)->buscar('"Juan Perez"');

    expect(array_column($resultado['resultados'], 'url'))->toBe([
        'https://laprensagrafica.com/nota-1',
        'https://elsalvador.com/nota-2',
    ])
        ->and($resultado['resultados'][0]['titulo'])->toBe('Nota 1')
        // strip_tags: Brave resalta coincidencias con <strong> en description.
        ->and($resultado['resultados'][0]['descripcion'])->toBe('Un resumen con HTML')
        ->and($resultado['resultados'][0]['medio'])->toBe('laprensagrafica.com')
        ->and($resultado['resultados'][0]['fecha'])->toBe('2026-09-23T20:59:49')
        // Segundo item sin meta_url/page_age - no debe tronar, solo null.
        ->and($resultado['resultados'][1]['medio'])->toBeNull()
        ->and($resultado['resultados'][1]['fecha'])->toBeNull();
});

it('devuelve una lista vacia si Brave Search no encuentra resultados', function () {
    Http::fake(['api.search.brave.com/*' => Http::response(['web' => ['results' => []]], 200)]);

    $resultado = (new BraveSearchAdapter)->buscar('nombre sin coincidencias');

    expect($resultado['resultados'])->toBe([]);
});

it('envia la key en el header X-Subscription-Token, no como query param', function () {
    config(['services.brave_search.api_key' => 'mi-key-secreta']);
    Http::fake(['api.search.brave.com/*' => Http::response(['web' => ['results' => []]], 200)]);

    (new BraveSearchAdapter)->buscar('prueba');

    Http::assertSent(function ($request) {
        return $request->hasHeader('X-Subscription-Token', 'mi-key-secreta')
            && ! str_contains((string) $request->url(), 'mi-key-secreta');
    });
});

it('rechaza una query de mas de 600 caracteres sin llamar a Brave ni gastar cuota', function () {
    config(['services.brave_search.monthly_limit' => 1]);
    Http::fake(['api.search.brave.com/*' => Http::response(['web' => ['results' => []]], 200)]);

    $adapter = new BraveSearchAdapter;

    expect(fn () => $adapter->buscar(str_repeat('a', 601)))->toThrow(InvalidArgumentException::class);
    Http::assertNothingSent();

    // La cuota no se consumio: la siguiente consulta valida si sale.
    $adapter->buscar('"Juan Perez"');
    Http::assertSentCount(1);
});

it('rechaza una query de mas de 75 palabras sin llamar a Brave', function () {
    Http::fake(['api.search.brave.com/*' => Http::response(['web' => ['results' => []]], 200)]);

    expect(fn () => (new BraveSearchAdapter)->buscar(implode(' ', array_fill(0, 76, 'a'))))
        ->toThrow(InvalidArgumentException::class);

    Http::assertNothingSent();
});

it('envia spellcheck=false y search_lang=es, sin country', function () {
    Http::fake(['api.search.brave.com/*' => Http::response(['web' => ['results' => []]], 200)]);

    (new BraveSearchAdapter)->buscar('"Christopher Yuvini Carrillo"');

    Http::assertSent(function ($request) {
        return $request['spellcheck'] === 'false'
            && $request['search_lang'] === 'es'
            && ! isset($request['country']);
    });
});

it('envia freshness como rango explicito (hoy - dias)to(hoy) cuando recibe dias', function () {
    Carbon\Carbon::setTestNow('2026-09-25 15:00:00');
    Http::fake(['api.search.brave.com/*' => Http::response(['web' => ['results' => []]], 200)]);

    (new BraveSearchAdapter)->buscar('"Juan Perez"', 60);

    Http::assertSent(fn ($request) => $request['freshness'] === '2026-07-27to2026-09-25');
    Carbon\Carbon::setTestNow();
});

it('no envia freshness si no recibe dias', function () {
    Http::fake(['api.search.brave.com/*' => Http::response(['web' => ['results' => []]], 200)]);

    (new BraveSearchAdapter)->buscar('"Juan Perez"');

    Http::assertSent(fn ($request) => ! isset($request['freshness']));
});

it('devuelve la metadata de la query que reporta Brave (original, altered, search_operators)', function () {
    Http::fake(['api.search.brave.com/*' => Http::response([
        'query' => [
            'original' => '"Juan Perez" site:a.com',
            'altered' => '"Juan Pérez" site:a.com',
            'spellcheck_off' => true,
            'search_operators' => ['applied' => true, 'sites' => ['a.com']],
            'otro_campo' => 'se ignora',
        ],
        'web' => ['results' => []],
    ], 200)]);

    $resultado = (new BraveSearchAdapter)->buscar('"Juan Perez" site:a.com');

    expect($resultado['metadata'])->toBe([
        'original' => '"Juan Perez" site:a.com',
        'altered' => '"Juan Pérez" site:a.com',
        'spellcheck_off' => true,
        'search_operators' => ['applied' => true, 'sites' => ['a.com']],
    ]);
});

it('devuelve metadata null si Brave no trae el bloque query', function () {
    Http::fake(['api.search.brave.com/*' => Http::response(['web' => ['results' => []]], 200)]);

    expect((new BraveSearchAdapter)->buscar('"Juan Perez"')['metadata'])->toBeNull();
});

it('nunca llama a Brave una vez alcanzada la cuota mensual configurada', function () {
    config(['services.brave_search.monthly_limit' => 2]);
    Http::fake(['api.search.brave.com/*' => Http::response(['web' => ['results' => []]], 200)]);

    $adapter = new BraveSearchAdapter;
    $adapter->buscar('"Juan Perez"');
    $adapter->buscar('"Juan Perez"');

    expect(fn () => $adapter->buscar('"Juan Perez"'))->toThrow(RuntimeException::class);

    Http::assertSentCount(2);
});

it('cuenta la cuota por separado entre meses distintos', function () {
    config(['services.brave_search.monthly_limit' => 1]);
    Http::fake(['api.search.brave.com/*' => Http::response(['web' => ['results' => []]], 200)]);

    $adapter = new BraveSearchAdapter;
    Carbon\Carbon::setTestNow('2026-09-30 12:00:00');
    $adapter->buscar('"Juan Perez"');
    expect(fn () => $adapter->buscar('"Juan Perez"'))->toThrow(RuntimeException::class);

    Carbon\Carbon::setTestNow('2026-10-01 00:00:01');
    $adapter->buscar('"Juan Perez"');

    Http::assertSentCount(2);
    Carbon\Carbon::setTestNow();
});
