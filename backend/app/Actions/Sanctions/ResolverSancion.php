<?php

declare(strict_types=1);

namespace App\Actions\Sanctions;

use App\Models\SanctionMatch;
use App\Models\User;
use RuntimeException;

/**
 * Resolucion humana de un hallazgo de sanciones (seccion 1, principio no
 * negociable). Solo desde 'pendiente': una resolucion firme no se reescribe
 * en silencio (queda en activity_log quien y cuando).
 */
class ResolverSancion
{
    public function handle(SanctionMatch $match, User $user, string $estado): SanctionMatch
    {
        if ($match->estado !== 'pendiente') {
            throw new RuntimeException("El hallazgo ya fue resuelto como '{$match->estado}'.");
        }

        $match->forceFill([
            'estado' => $estado,
            'resuelto_por' => $user->id,
            'resuelto_en' => now(),
        ])->save();

        return $match;
    }
}
