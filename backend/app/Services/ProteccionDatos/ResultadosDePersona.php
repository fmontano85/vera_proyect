<?php

declare(strict_types=1);

namespace App\Services\ProteccionDatos;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Que search_results son de una persona (seccion 3.9, puntos 4 y 5): los
 * de su consulta puntual (subject_id) y tambien los de una busqueda por
 * tags (subject_id null) donde se hizo una captura manual atribuida a
 * ella - su PDF de evidencia es evidencia de esa persona. Un solo lugar
 * para que el borrado y la exportacion no diverjan (hallazgo del
 * code-review 2026-09-28).
 */
final class ResultadosDePersona
{
    /** @return Collection<int, int> */
    public static function ids(int $subjectId): Collection
    {
        $porCapturaManual = DB::table('mentions')
            ->where('origen', 'manual')
            ->whereNotNull('search_result_id')
            ->whereIn('id', DB::table('matches')->where('subject_id', $subjectId)->select('mention_id'))
            ->pluck('search_result_id');

        return DB::table('search_results')
            ->where('subject_id', $subjectId)
            ->orWhereIn('id', $porCapturaManual)
            ->orderBy('id')
            ->pluck('id');
    }
}
