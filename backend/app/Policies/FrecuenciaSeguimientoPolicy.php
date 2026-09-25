<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;

/**
 * Seccion 3.8/3.2: todos los roles del tenant ven los dias por nivel de
 * riesgo; solo admin ("configuracion del tenant") los cambia.
 */
class FrecuenciaSeguimientoPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function update(User $user): bool
    {
        return $user->hasRole('admin');
    }
}
