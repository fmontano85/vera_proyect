<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\SearchTag;
use App\Models\User;

/**
 * Catalogo de tags de busqueda (sesion posterior a la 3.7). Mismo set de
 * roles que iniciar una busqueda (SubjectPolicy::buscar) - lectura no
 * gestiona el catalogo ni dispara busquedas, superadmin recibe 403 en
 * toda ruta de tenant (regla existente).
 */
class SearchTagPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'oficial_cumplimiento', 'analista']);
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'oficial_cumplimiento', 'analista']);
    }

    public function buscar(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'oficial_cumplimiento', 'analista']);
    }
}
