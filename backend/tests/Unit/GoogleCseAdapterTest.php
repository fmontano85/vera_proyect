<?php

declare(strict_types=1);

use App\Sources\GoogleCseAdapter;
use Illuminate\Support\Facades\Http;

it('extrae las urls de una respuesta real de Google CSE', function () {
    // Forma real de la respuesta de Google Custom Search JSON API
    // (campos irrelevantes recortados).
    $respuesta = [
        'kind' => 'customsearch#search',
        'items' => [
            ['title' => 'Nota 1', 'link' => 'https://laprensagrafica.com/nota-1', 'snippet' => '...'],
            ['title' => 'Nota 2', 'link' => 'https://elsalvador.com/nota-2', 'snippet' => '...'],
        ],
    ];

    Http::fake(['www.googleapis.com/*' => Http::response($respuesta, 200)]);

    $resultado = (new GoogleCseAdapter())->buscar('"Juan Perez"');

    expect($resultado['urls'])->toBe([
        'https://laprensagrafica.com/nota-1',
        'https://elsalvador.com/nota-2',
    ]);
});

it('devuelve una lista vacia si Google CSE no encuentra resultados', function () {
    Http::fake(['www.googleapis.com/*' => Http::response(['kind' => 'customsearch#search'], 200)]);

    $resultado = (new GoogleCseAdapter())->buscar('nombre sin coincidencias');

    expect($resultado['urls'])->toBe([]);
});

it('nunca llama a Google una vez alcanzada la cuota diaria configurada', function () {
    config(['services.google_cse.daily_limit' => 2]);
    Http::fake(['www.googleapis.com/*' => Http::response(['items' => []], 200)]);

    $adapter = new GoogleCseAdapter();
    $adapter->buscar('"Juan Perez"');
    $adapter->buscar('"Juan Perez"');

    expect(fn () => $adapter->buscar('"Juan Perez"'))->toThrow(RuntimeException::class);

    // La garantia real no es que Google devuelva un error - es que la
    // tercera llamada nunca sale de la aplicacion.
    Http::assertSentCount(2);
});

it('cuenta la cuota por separado entre dias distintos', function () {
    config(['services.google_cse.daily_limit' => 1]);
    Http::fake(['www.googleapis.com/*' => Http::response(['items' => []], 200)]);

    $adapter = new GoogleCseAdapter();
    Carbon\Carbon::setTestNow('2026-09-14 12:00:00');
    $adapter->buscar('"Juan Perez"');
    expect(fn () => $adapter->buscar('"Juan Perez"'))->toThrow(RuntimeException::class);

    Carbon\Carbon::setTestNow('2026-09-15 00:00:01');
    $adapter->buscar('"Juan Perez"');

    Http::assertSentCount(2);
    Carbon\Carbon::setTestNow();
});
