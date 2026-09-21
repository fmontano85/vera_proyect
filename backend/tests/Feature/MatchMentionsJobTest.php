<?php

declare(strict_types=1);

use App\Jobs\MatchMentionsJob;
use App\Models\Mention;
use App\Models\MentionMatch;
use App\Models\Subject;
use Stancl\Tenancy\Database\Models\Tenant;

/**
 * Meilisearch indexa de forma asincrona (el HTTP call a
 * Model::searchable() devuelve antes de que el documento sea buscable).
 * Contra la instancia real local esto tarda tipicamente milisegundos,
 * pero no es instantaneo - se reintenta un poco en vez de asumir.
 */
function esperarHasta(callable $condicion, int $intentosMax = 20, int $esperaMs = 100): void
{
    for ($intento = 0; $intento < $intentosMax; $intento++) {
        if ($condicion()) {
            return;
        }
        usleep($esperaMs * 1000);
    }
}

it('crea un match pendiente cuando el nombre de la mencion coincide con un subject del tenant', function () {
    $tenant = Tenant::create();

    tenancy()->initialize($tenant);
    $subject = Subject::factory()->create(['nombre_canonico' => 'Roberto Alfredo Handal']);
    tenancy()->end();

    esperarHasta(fn () => Subject::search('Roberto Alfredo Handal')->where('tenant_id', $tenant->id)->get()->isNotEmpty());

    $mention = Mention::factory()->create(['nombre_extraido' => 'Roberto Alfredo Handal']);

    (new MatchMentionsJob($mention->id))->handle();

    tenancy()->initialize($tenant);
    $match = MentionMatch::where('mention_id', $mention->id)->where('subject_id', $subject->id)->first();
    tenancy()->end();

    expect($match)->not->toBeNull()
        ->and($match->estado)->toBe('pendiente')
        ->and($match->tenant_id)->toBe($tenant->id);
});

it('genera un match por cada tenant cuyo subject coincide, sin cruzar tenant_id', function () {
    $tenantA = Tenant::create();
    $tenantB = Tenant::create();

    tenancy()->initialize($tenantA);
    $subjectA = Subject::factory()->create(['nombre_canonico' => 'Xiomara Delgado Contreras']);
    tenancy()->end();

    tenancy()->initialize($tenantB);
    $subjectB = Subject::factory()->create(['nombre_canonico' => 'Xiomara Delgado Contreras']);
    tenancy()->end();

    esperarHasta(fn () => Subject::search('Xiomara Delgado Contreras')->where('tenant_id', $tenantA->id)->get()->isNotEmpty()
        && Subject::search('Xiomara Delgado Contreras')->where('tenant_id', $tenantB->id)->get()->isNotEmpty());

    $mention = Mention::factory()->create(['nombre_extraido' => 'Xiomara Delgado Contreras']);

    (new MatchMentionsJob($mention->id))->handle();

    tenancy()->initialize($tenantA);
    $matchA = MentionMatch::where('mention_id', $mention->id)->first();
    tenancy()->end();

    tenancy()->initialize($tenantB);
    $matchB = MentionMatch::where('mention_id', $mention->id)->first();
    tenancy()->end();

    expect($matchA->subject_id)->toBe($subjectA->id)
        ->and($matchA->tenant_id)->toBe($tenantA->id)
        ->and($matchB->subject_id)->toBe($subjectB->id)
        ->and($matchB->tenant_id)->toBe($tenantB->id);
});

it('es idempotente: correr el job dos veces no duplica el match', function () {
    $tenant = Tenant::create();

    tenancy()->initialize($tenant);
    Subject::factory()->create(['nombre_canonico' => 'Concepcion Estrada Melara']);
    tenancy()->end();

    esperarHasta(fn () => Subject::search('Concepcion Estrada Melara')->where('tenant_id', $tenant->id)->get()->isNotEmpty());

    $mention = Mention::factory()->create(['nombre_extraido' => 'Concepcion Estrada Melara']);

    (new MatchMentionsJob($mention->id))->handle();
    (new MatchMentionsJob($mention->id))->handle();

    tenancy()->initialize($tenant);
    expect(MentionMatch::where('mention_id', $mention->id)->count())->toBe(1);
    tenancy()->end();
});

it('no crea matches si ningun subject de ningun tenant se parece al nombre', function () {
    $tenant = Tenant::create();

    tenancy()->initialize($tenant);
    Subject::factory()->create(['nombre_canonico' => 'Nombre Totalmente Distinto']);
    tenancy()->end();

    $mention = Mention::factory()->create(['nombre_extraido' => 'Zzqxvw Ppllkjh Nomatch']);

    (new MatchMentionsJob($mention->id))->handle();

    tenancy()->initialize($tenant);
    expect(MentionMatch::count())->toBe(0);
    tenancy()->end();
});
