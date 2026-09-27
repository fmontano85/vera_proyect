<?php

declare(strict_types=1);

namespace App\Services\Sanctions;

use App\Models\SanctionEntry;
use App\Models\SanctionMatch;
use App\Models\Subject;
use App\Services\Matching\NameNormalizer;

/**
 * Cruza UN subject (nombre canonico + aliases) contra el indice de
 * sanciones (seccion 3.4 del CLAUDE.md raiz). Debe correr con tenancy
 * inicializada para el tenant del subject: SanctionMatch usa BelongsToTenant.
 *
 * El sistema solo PROPONE: todo hallazgo nace 'pendiente' y lo resuelve una
 * persona (seccion 1). Un hallazgo ya resuelto nunca se reabre ni se
 * degrada; si vuelve a aparecer con mas puntaje solo sube el puntaje de uno
 * pendiente. El umbral (config vera.sanciones_score_minimo) es provisional.
 */
class CruceSanciones
{
    private const MAX_HITS_POR_NOMBRE = 5;

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
            ->unique();

        foreach ($nombres as $nombre) {
            $resultado = SanctionEntry::search(NameNormalizer::normalize($nombre))
                ->options(['showRankingScore' => true, 'limit' => self::MAX_HITS_POR_NOMBRE])
                ->raw();

            foreach ($resultado['hits'] ?? [] as $hit) {
                $score = round(($hit['_rankingScore'] ?? 0) * 100, 2);
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
}
