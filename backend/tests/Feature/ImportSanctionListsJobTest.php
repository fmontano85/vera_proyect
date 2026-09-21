<?php

declare(strict_types=1);

use App\Jobs\ImportSanctionListsJob;
use App\Models\SanctionEntry;
use App\Models\SanctionList;
use Illuminate\Support\Facades\Http;

function fakeOfacResponse(): void
{
    $csv = file_get_contents(__DIR__.'/../Fixtures/ofac_sdn_sample.csv');

    Http::fake([
        'www.treasury.gov/*' => Http::response($csv, 200),
    ]);
}

it('importa el SDN de OFAC y crea sanction_entries', function () {
    fakeOfacResponse();

    (new ImportSanctionListsJob('ofac_sdn'))->handle();

    expect(SanctionEntry::count())->toBe(12);

    $list = SanctionList::where('codigo', 'ofac_sdn')->first();
    expect($list)->not->toBeNull()
        ->and($list->fecha_importacion)->not->toBeNull();
});

it('es idempotente: reimportar el mismo feed no duplica entries', function () {
    fakeOfacResponse();

    (new ImportSanctionListsJob('ofac_sdn'))->handle();
    (new ImportSanctionListsJob('ofac_sdn'))->handle();

    expect(SanctionEntry::count())->toBe(12);
});

it('actualiza una entry existente en vez de duplicarla si cambia el nombre', function () {
    $csvOriginal = file_get_contents(__DIR__.'/../Fixtures/ofac_sdn_sample.csv');
    $csvActualizado = str_replace('AEROCARIBBEAN AIRLINES', 'AEROCARIBBEAN AIRLINES SA', $csvOriginal);

    // Http::fake() apila stubs y usa el primero que matchea el patron, no
    // el ultimo - para simular dos descargas distintas del mismo feed
    // hace falta una secuencia, no dos Http::fake() con el mismo patron.
    Http::fakeSequence('www.treasury.gov/*')
        ->push($csvOriginal, 200)
        ->push($csvActualizado, 200);

    (new ImportSanctionListsJob('ofac_sdn'))->handle();
    (new ImportSanctionListsJob('ofac_sdn'))->handle();

    expect(SanctionEntry::count())->toBe(12);

    $list = SanctionList::where('codigo', 'ofac_sdn')->first();
    $entry = SanctionEntry::where('sanction_list_id', $list->id)->where('external_id', '36')->first();
    expect($entry->nombre)->toBe('AEROCARIBBEAN AIRLINES SA');
});

it('rechaza codigos de lista sin adaptador implementado (un_consolidated, eu)', function (string $codigo) {
    expect(fn () => (new ImportSanctionListsJob($codigo))->handle())
        ->toThrow(InvalidArgumentException::class);
})->with(['un_consolidated', 'eu']);
