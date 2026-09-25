<?php

declare(strict_types=1);

use App\Services\Search\LimiteDeQuery;

it('arma la query con terminos entre comillas y los site: agrupados', function () {
    $resultado = LimiteDeQuery::construir(
        collect(['Juan Perez', 'J. Perez']),
        collect(['site:a.com', 'site:b.com']),
    );

    expect($resultado['query'])->toBe('("Juan Perez" OR "J. Perez") (site:a.com OR site:b.com)')
        ->and($resultado['omitidos'])->toBe([]);
});

it('cuenta palabras separadas por espacios', function () {
    expect(LimiteDeQuery::contarPalabras('("Juan Perez" OR "J") (site:a.com)'))->toBe(5);
});

it('omite terminos desde el final hasta respetar 75 palabras, sin quitar nunca el primero ni los site:', function () {
    // 7 sites + 6 OR = 13 palabras; canonico = 3 palabras.
    $sites = collect(range(1, 7))->map(fn ($i) => "site:medio{$i}.com");
    // 12 aliases de 5 palabras = 60 palabras + 11 OR: se pasa de 75.
    $aliases = collect(range(1, 12))->map(fn ($i) => "alias{$i} uno dos tres cuatro");

    $resultado = LimiteDeQuery::construir(collect(['Juan Carlos Perez'])->merge($aliases), $sites);

    expect(LimiteDeQuery::contarPalabras($resultado['query']))->toBeLessThanOrEqual(LimiteDeQuery::MAX_PALABRAS)
        ->and($resultado['query'])->toContain('"Juan Carlos Perez"')
        ->and($resultado['query'])->toContain('site:medio7.com')
        ->and($resultado['omitidos'])->not->toBeEmpty()
        // Se quitan los del final: el ultimo alias siempre sale primero.
        ->and($resultado['omitidos'])->toContain('alias12 uno dos tres cuatro')
        ->and($resultado['query'])->toContain('alias1 uno');
});

it('omite terminos desde el final hasta respetar 600 caracteres', function () {
    $sites = collect(['site:a.com']);
    $aliases = collect(range(1, 10))->map(fn ($i) => str_repeat('x', 100).$i);

    $resultado = LimiteDeQuery::construir(collect(['Canonico'])->merge($aliases), $sites);

    expect(mb_strlen($resultado['query']))->toBeLessThanOrEqual(LimiteDeQuery::MAX_CARACTERES)
        ->and($resultado['query'])->toContain('"Canonico"')
        ->and($resultado['omitidos'])->not->toBeEmpty();
});

it('lanza excepcion si ni siquiera el primer termino con los site: cabe en el limite', function () {
    expect(fn () => LimiteDeQuery::construir(collect([str_repeat('x', 700)]), collect(['site:a.com'])))
        ->toThrow(InvalidArgumentException::class);
});

it('detecta si una query ya armada excede alguno de los dos limites', function () {
    expect(LimiteDeQuery::excede(str_repeat('a', 601)))->toBeTrue()
        ->and(LimiteDeQuery::excede(implode(' ', array_fill(0, 76, 'a'))))->toBeTrue()
        ->and(LimiteDeQuery::excede('"Juan Perez" site:a.com'))->toBeFalse();
});
