<?php

declare(strict_types=1);

use App\Sources\Sanctions\OfacSdnAdapter;
use Illuminate\Support\Facades\Http;

it('parsea el csv real de OFAC (respuesta grabada) a entradas normalizadas', function () {
    $csv = file_get_contents(__DIR__.'/../Fixtures/ofac_sdn_sample.csv');

    Http::fake([
        'www.treasury.gov/*' => Http::response($csv, 200),
    ]);

    $entries = iterator_to_array((new OfacSdnAdapter())->fetch());

    expect($entries)->toHaveCount(12);

    $first = $entries[0];
    expect($first['external_id'])->toBe('36')
        ->and($first['nombre'])->toBe('AEROCARIBBEAN AIRLINES')
        ->and($first['programa'])->toBe('CUBA')
        ->and($first['tipo'])->toBeNull()
        ->and($first['aliases'])->toBe([])
        ->and($first['pais'])->toBeNull();

    $withRemarks = collect($entries)->firstWhere('external_id', '306');
    expect($withRemarks['nombre'])->toBe('BANCO NACIONAL DE CUBA')
        ->and($withRemarks['raw_json']['remarks'])->toBe("a.k.a. 'BNC'.");
});
