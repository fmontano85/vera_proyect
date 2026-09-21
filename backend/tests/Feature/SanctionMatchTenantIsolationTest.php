<?php

declare(strict_types=1);

use App\Models\SanctionMatch;
use App\Models\Subject;
use Stancl\Tenancy\Database\Models\Tenant;

it('no lista sanction_matches de otro tenant', function () {
    $tenantA = Tenant::create();
    $tenantB = Tenant::create();

    tenancy()->initialize($tenantB);
    SanctionMatch::factory()->create();
    tenancy()->end();

    tenancy()->initialize($tenantA);
    expect(SanctionMatch::count())->toBe(0);
    tenancy()->end();

    tenancy()->initialize($tenantB);
    expect(SanctionMatch::count())->toBe(1);
    tenancy()->end();
});

it('un sanction_match toma el tenant_id de su subject cuando el tenant ambiente coincide', function () {
    $tenantA = Tenant::create();

    tenancy()->initialize($tenantA);
    $subjectA = Subject::factory()->create();
    $match = SanctionMatch::factory()->for($subjectA, 'subject')->create();
    tenancy()->end();

    expect($match->tenant_id)->toBe($tenantA->id);
});

it('falla al crear un sanction_match si el tenant ambiente no es el del subject', function () {
    $tenantA = Tenant::create();
    $tenantB = Tenant::create();

    tenancy()->initialize($tenantA);
    $subjectA = Subject::factory()->create();
    tenancy()->end();

    tenancy()->initialize($tenantB);
    expect(fn () => SanctionMatch::factory()->for($subjectA, 'subject')->create())
        ->toThrow(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
    tenancy()->end();
});

it('sanction_lists y sanction_entries son visibles para cualquier tenant (catalogo global)', function () {
    $tenantA = Tenant::create();
    $tenantB = Tenant::create();

    $entry = \App\Models\SanctionEntry::factory()->create();

    tenancy()->initialize($tenantA);
    expect(\App\Models\SanctionEntry::find($entry->id))->not->toBeNull();
    tenancy()->end();

    tenancy()->initialize($tenantB);
    expect(\App\Models\SanctionEntry::find($entry->id))->not->toBeNull();
    tenancy()->end();
});
