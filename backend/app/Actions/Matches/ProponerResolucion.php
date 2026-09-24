<?php

declare(strict_types=1);

namespace App\Actions\Matches;

use App\Models\MentionMatch;
use App\Models\User;
use RuntimeException;

/**
 * Paso 1 del flujo de resolucion (seccion 3.2 del CLAUDE.md raiz):
 * "analista... propone resoluciones, no aprueba". No cambia 'estado' -
 * eso es exclusivo de ResolverMatch, que decide si sigue la propuesta o
 * no.
 */
class ProponerResolucion
{
    public function handle(MentionMatch $match, User $user, string $estadoPropuesto): MentionMatch
    {
        if ($match->estado !== 'pendiente') {
            throw new RuntimeException(
                'Esta coincidencia ya fue resuelta - no se puede proponer sobre un match cerrado.'
            );
        }

        $match->forceFill([
            'propuesta_estado' => $estadoPropuesto,
            'propuesta_por' => $user->id,
            'propuesta_en' => now(),
        ])->save();

        return $match;
    }
}
