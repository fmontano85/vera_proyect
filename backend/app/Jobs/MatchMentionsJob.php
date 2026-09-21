<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Mention;
use App\Models\MentionMatch;
use App\Models\Subject;
use App\Services\Matching\NameNormalizer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Stancl\Tenancy\Contracts\Tenant;

/**
 * mentions es global (sin tenant_id) - la misma mencion puede coincidir
 * con subjects de varios tenants distintos, asi que este job recorre
 * TODOS los tenants (tenancy()->runForMultiple(), patron ya usado por
 * ImportSanctionListsJob/MatchSanctionsJob en la seccion 3.4) consultando,
 * dentro de cada uno, solo los subjects de ESE tenant.
 *
 * Umbral de score y "rarity gate" por frecuencia de apellidos: sin
 * calibrar todavia (seccion 9 del CLAUDE.md raiz - depende de datos
 * reales de Fase 0). Mientras tanto, sin excepcion: "todo match es
 * pendiente" (seccion 9, textual) - este job no filtra por score, solo
 * registra lo que Meilisearch devuelve para que un analista lo resuelva.
 */
class MatchMentionsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(private readonly int $mentionId)
    {
        $this->onQueue('matching');
    }

    public function handle(): void
    {
        $mention = Mention::findOrFail($this->mentionId);
        $consulta = NameNormalizer::normalize($mention->nombre_extraido);

        tenancy()->runForMultiple(null, function (Tenant $tenant) use ($mention, $consulta) {
            $resultados = Subject::search($consulta)
                ->where('tenant_id', $tenant->getTenantKey())
                ->options(['showRankingScore' => true])
                ->raw();

            foreach ($resultados['hits'] ?? [] as $hit) {
                MentionMatch::updateOrCreate(
                    [
                        'mention_id' => $mention->id,
                        'subject_id' => $hit['id'],
                    ],
                    [
                        'score_meilisearch' => round(($hit['_rankingScore'] ?? 0) * 100, 2),
                        'estado' => 'pendiente',
                    ],
                );
            }
        });
    }
}
