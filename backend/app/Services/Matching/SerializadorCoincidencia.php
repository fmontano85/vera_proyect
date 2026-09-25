<?php

declare(strict_types=1);

namespace App\Services\Matching;

use App\Models\MentionMatch;
use App\Models\SearchResult;

/**
 * JSON de una coincidencia para el dashboard. Armado explicito (no
 * toArray): controla exactamente que sale y evita que la relacion
 * propuestaPor pise la columna FK 'propuesta_por' al serializar.
 *
 * Aislamiento: mentions/articles son globales pero search_results es por
 * tenant - el search_result de una mencion automatica puede ser de OTRO
 * tenant (el primero que encontro el articulo). La relacion esta scopeada
 * por tenancy, asi que en ese caso llega null; el contexto publico sale
 * del article global.
 */
class SerializadorCoincidencia
{
    /** Relaciones que serializar() necesita cargadas. */
    public const RELACIONES = [
        'subject:id,nombre_canonico,nivel_riesgo,activo',
        'mention.article:id,url,titulo,medio,fecha_publicacion',
        'mention.searchResult:id,url,titulo,medio,fecha_brave',
        'propuestaPor:id,name',
    ];

    /**
     * @return array<string, mixed>
     */
    public function serializar(MentionMatch $match): array
    {
        $mention = $match->mention;
        /** @var SearchResult|null $resultado */
        $resultado = $mention?->searchResult;

        return [
            'id' => $match->id,
            'estado' => $match->estado,
            'score_meilisearch' => $match->score_meilisearch,
            'propuesta_estado' => $match->propuesta_estado,
            'propuesta_en' => $match->propuesta_en?->toIso8601String(),
            'propuesta_por_usuario' => $match->propuestaPor === null ? null : [
                'id' => $match->propuestaPor->id,
                'name' => $match->propuestaPor->name,
            ],
            'created_at' => $match->created_at?->toIso8601String(),
            'subject' => $match->subject?->only(['id', 'nombre_canonico', 'nivel_riesgo', 'activo']),
            'mention' => $mention === null ? null : [
                'id' => $mention->id,
                'nombre_extraido' => $mention->nombre_extraido,
                'rol' => $mention->rol,
                'delitos' => $mention->delitos,
                'resumen' => $mention->resumen,
                'fecha_hecho' => $mention->fecha_hecho,
                'origen' => $mention->origen,
                'article' => $mention->article?->only(['id', 'url', 'titulo', 'medio', 'fecha_publicacion']),
                'search_result' => $resultado?->only(['id', 'url', 'titulo', 'medio', 'fecha_brave']),
            ],
        ];
    }
}
