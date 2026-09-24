<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\MentionMatch;
use App\Models\User;

/**
 * Flujo de resolucion en dos pasos (seccion 3.2 del CLAUDE.md raiz):
 * "analista ejecuta consultas, propone resoluciones, no aprueba" vs
 * "oficial_cumplimiento resuelve coincidencias, aprueba reportes" - por
 * eso 'resolver' excluye a analista a proposito, no es un descuido.
 */
class MentionMatchPolicy
{
    public function view(User $user, MentionMatch $match): bool
    {
        return true;
    }

    public function proponer(User $user, MentionMatch $match): bool
    {
        return $user->hasAnyRole(['admin', 'oficial_cumplimiento', 'analista']);
    }

    public function resolver(User $user, MentionMatch $match): bool
    {
        return $user->hasAnyRole(['admin', 'oficial_cumplimiento']);
    }
}
