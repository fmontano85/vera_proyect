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

    $resultado = (new BraveSearchAdapter())->buscar('"Juan Perez"');

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

    $resultado = (new BraveSearchAdapter())->buscar('nombre sin coincidencias');

    expect($resultado['resultados'])->toBe([]);
});

it('envia la key en el header X-Subscription-Token, no como query param', function () {
    config(['services.brave_search.api_key' => 'mi-key-secreta']);
    Http::fake(['api.search.brave.com/*' => Http::response(['web' => ['results' => []]], 200)]);

    (new BraveSearchAdapter())->buscar('prueba');

    Http::assertSent(function ($request) {
        return $request->hasHeader('X-Subscription-Token', 'mi-key-secreta')
            && ! str_contains((string) $request->url(), 'mi-key-secreta');
    });
});

it('trunca la query a 600 caracteres antes de enviarla', function () {
    Http::fake(['api.search.brave.com/*' => Http::response(['web' => ['results' => []]], 200)]);

    $queryLarga = str_repeat('a', 1000);
    (new BraveSearchAdapter())->buscar($queryLarga);

    Http::assertSent(function ($request) {
        return strlen((string) $request['q']) === 600;
    });
});

it('nunca llama a Brave una vez alcanzada la cuota mensual configurada', function () {
    config(['services.brave_search.monthly_limit' => 2]);
    Http::fake(['api.search.brave.com/*' => Http::response(['web' => ['results' => []]], 200)]);

    $adapter = new BraveSearchAdapter();
    $adapter->buscar('"Juan Perez"');
    $adapter->buscar('"Juan Perez"');

    expect(fn () => $adapter->buscar('"Juan Perez"'))->toThrow(RuntimeException::class);

    Http::assertSentCount(2);
});

it('cuenta la cuota por separado entre meses distintos', function () {
    config(['services.brave_search.monthly_limit' => 1]);
    Http::fake(['api.search.brave.com/*' => Http::response(['web' => ['results' => []]], 200)]);

    $adapter = new BraveSearchAdapter();
    Carbon\Carbon::setTestNow('2026-09-30 12:00:00');
    $adapter->buscar('"Juan Perez"');
    expect(fn () => $adapter->buscar('"Juan Perez"'))->toThrow(RuntimeException::class);

    Carbon\Carbon::setTestNow('2026-10-01 00:00:01');
    $adapter->buscar('"Juan Perez"');

    Http::assertSentCount(2);
    Carbon\Carbon::setTestNow();
});
