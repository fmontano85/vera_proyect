<?php

declare(strict_types=1);

namespace App\Actions\Matches;

use App\Models\MentionMatch;
use App\Models\User;
use RuntimeException;

/**
 * Paso 2 (final) del flujo de resolucion (seccion 3.2 del CLAUDE.md raiz):
 * "oficial_cumplimiento resuelve coincidencias" - la Policy ya restringe
 * quien puede llamar esto (analista queda fuera a proposito). No exige
 * que exista una propuesta previa: el oficial puede resolver directo, y
 * puede coincidir con lo propuesto por el analista o no - la discrepancia
 * queda visible en los datos (propuesta_estado vs estado), no se oculta.
 */
class ResolverMatch
{
    public function handle(MentionMatch $match, User $user, string $estadoFinal): MentionMatch
    {
        if ($match->estado !== 'pendiente') {
            throw new RuntimeException('Esta coincidencia ya fue resuelta.');
        }

        $match->forceFill([
            'estado' => $estadoFinal,
            'resuelto_por' => $user->id,
            'resuelto_en' => now(),
        ])->save();

        return $match;
    }
}
