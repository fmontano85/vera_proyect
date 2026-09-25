<?php

declare(strict_types=1);

namespace App\Services\Matching;

use App\Exceptions\IndiceBusquedaNoDisponible;
use App\Models\Subject;
use Throwable;

/**
 * Punto unico para mantener al dia el indice 'subjects' de Meilisearch
 * que usa MatchMentionsJob (nombre canonico + aliases, filtrado por
 * tenant). Los aliases viven en otra tabla: guardar un SubjectAlias no
 * dispara el observer de Scout del Subject, asi que quien los cambie debe
 * llamar aqui. Llamarlo dentro de la transaccion del cambio: si
 * Meilisearch falla, lanza IndiceBusquedaNoDisponible y se revierte todo.
 */
class IndiceSubjects
{
    public function reindexar(Subject $subject): void
    {
        try {
            $subject->load('aliases');

            // Inactivo = fuera del matching (shouldBeSearchable()).
            $subject->shouldBeSearchable() ? $subject->searchable() : $subject->unsearchable();
        } catch (Throwable $e) {
            throw new IndiceBusquedaNoDisponible('No se pudo reindexar el subject '.$subject->id.': '.$e->getMessage(), previous: $e);
        }
    }
}
