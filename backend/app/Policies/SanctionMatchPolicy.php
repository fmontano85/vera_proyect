<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\SanctionMatch;
use App\Models\User;

/**
 * Mismo criterio que MentionMatchPolicy: ver todos los roles del tenant;
 * cruzar un subject lo puede pedir quien ya puede consultar; resolver en
 * firme solo oficial_cumplimiento/admin (seccion 3.2).
 */
class SanctionMatchPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function cruzar(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'oficial_cumplimiento', 'analista']);
    }

    public function resolver(User $user, SanctionMatch $match): bool
    {
        return $user->hasAnyRole(['admin', 'oficial_cumplimiento']);
    }
}
