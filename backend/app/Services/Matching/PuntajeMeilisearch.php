<?php

declare(strict_types=1);

namespace App\Services\Matching;

/**
 * Convierte el _rankingScore de Meilisearch (0.0-1.0) al puntaje 0-100 que
 * usa VERA en matches.score_meilisearch y sanction_matches.score. Punto
 * unico compartido por MatchMentionsJob y CruceSanciones - antes de esto,
 * cada uno repetia la misma formula: si el criterio de escala/redondeo
 * cambia en la calibracion de Fase 0 (seccion 9 del CLAUDE.md raiz), debe
 * cambiar en un solo lugar.
 */
class PuntajeMeilisearch
{
    public static function desdeHit(array $hit): float
    {
        return round(($hit['_rankingScore'] ?? 0) * 100, 2);
    }
}
