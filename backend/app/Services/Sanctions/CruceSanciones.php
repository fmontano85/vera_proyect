<?php

declare(strict_types=1);

namespace App\Services\Sanctions;

use App\Models\SanctionEntry;
use App\Models\SanctionMatch;
use App\Models\Subject;
use App\Services\Matching\NameNormalizer;
use App\Services\Matching\PuntajeMeilisearch;
use Meilisearch\Client;
use Meilisearch\Contracts\SearchQuery;

/**
 * Cruza UN subject (nombre canonico + aliases) contra el indice de
 * sanciones (seccion 3.4 del CLAUDE.md raiz). Debe correr con tenancy
 * inicializada para el tenant del subject: SanctionMatch usa BelongsToTenant.
 *
 * El sistema solo PROPONE: todo hallazgo nace 'pendiente' y lo resuelve una
 * persona (seccion 1). Un hallazgo ya resuelto nunca se reabre ni se
 * degrada; si vuelve a aparecer con mas puntaje solo sube el puntaje de uno
 * pendiente. El umbral (config vera.sanciones_score_minimo) es provisional.
 *
 * $client acepta null a proposito: MatchSanctionsJob usa
 * `CruceSanciones $cruce = new CruceSanciones` como valor por defecto (no
 * pasa por el contenedor), y varios tests instancian `new CruceSanciones()`
 * directo - sin un default aqui, ambos truenan por falta del argumento.
 * Resuelto al vuelo via el helper app() cuando no se inyecta explicito.
 */
class CruceSanciones
{
    private const MAX_HITS_POR_NOMBRE = 5;

    public function __construct(private readonly ?Client $client = null) {}

    /** @return int hallazgos NUEVOS creados en esta corrida */
    public function cruzar(Subject $subject): int
    {
        $minimo = (float) config('vera.sanciones_score_minimo');
        $nuevos = 0;

        // Propiedad, no metodo: si el caller (MatchSanctionsJob) ya hizo
        // with('aliases'), esto usa esa relacion cargada en vez de
        // disparar una query nueva por cada subject.
        $nombres = collect([$subject->nombre_canonico])
            ->merge($subject->aliases->pluck('nombre'))
            ->unique()
            ->values();

        if ($nombres->isEmpty()) {
            return $nuevos;
        }

        /**
         * Un solo request a Meilisearch (multi-search) para el nombre
         * canonico + hasta 20 aliases (seccion 3.3: max de aliases), en vez
         * de uno secuencial por nombre - un subject con muchos aliases ya
         * no paga hasta 21 round-trips de red por corrida, y la corrida
         * semanal de MatchSanctionsJob no multiplica eso por cada subject
         * activo de cada tenant.
         */
        $indice = (new SanctionEntry)->searchableAs();
        $consultas = $nombres->map(fn (string $nombre) => (new SearchQuery())
            ->setIndexUid($indice)
            ->setQuery(NameNormalizer::normalize($nombre))
            ->setShowRankingScore(true)
            ->setLimit(self::MAX_HITS_POR_NOMBRE))->all();

        $respuesta = $this->cliente()->multiSearch($consultas);

        foreach ($respuesta['results'] ?? [] as $resultadoPorNombre) {
            foreach ($resultadoPorNombre['hits'] ?? [] as $hit) {
                $score = PuntajeMeilisearch::desdeHit($hit);
                if ($score < $minimo) {
                    continue;
                }

                $match = SanctionMatch::firstOrNew([
                    'subject_id' => $subject->id,
                    'sanction_entry_id' => $hit['id'],
                ]);

                if (! $match->exists) {
                    $match->fill(['score' => $score, 'estado' => 'pendiente'])->save();
                    $nuevos++;
                } elseif ($match->estado === 'pendiente' && $score > (float) $match->score) {
                    $match->update(['score' => $score]);
                }
            }
        }

        return $nuevos;
    }

    private function cliente(): Client
    {
        return $this->client ?? app(Client::class);
    }
}
