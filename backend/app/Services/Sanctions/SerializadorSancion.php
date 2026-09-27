<?php

declare(strict_types=1);

namespace App\Services\Sanctions;

use App\Models\SanctionMatch;

/**
 * JSON de un hallazgo de sanciones - sin exponer raw_json ni ids internos
 * de mas. Punto unico de las relaciones a cargar (ListarSanciones::handle
 * y SancionController::resolver la reutilizan) para que ambos endpoints
 * sirvan siempre el mismo shape.
 */
class SerializadorSancion
{
    public const RELACIONES = [
        'subject:id,nombre_canonico',
        'sanctionEntry.sanctionList:id,codigo',
        'resueltoPor:id,name',
    ];

    /** @return array<string, mixed> */
    public function serializar(SanctionMatch $m): array
    {
        return [
            'id' => $m->id,
            'subject' => $m->subject ? ['id' => $m->subject->id, 'nombre_canonico' => $m->subject->nombre_canonico] : null,
            'entrada' => [
                'nombre' => $m->sanctionEntry->nombre,
                'aliases' => $m->sanctionEntry->aliases ?? [],
                'tipo' => $m->sanctionEntry->tipo,
                'programa' => $m->sanctionEntry->programa,
                'pais' => $m->sanctionEntry->pais,
                'lista' => $m->sanctionEntry->sanctionList?->codigo,
            ],
            'score' => (float) $m->score,
            'estado' => $m->estado,
            'resuelto_por' => $m->resueltoPor?->name,
            'resuelto_en' => $m->resuelto_en,
            'creado_en' => $m->created_at,
        ];
    }
}
