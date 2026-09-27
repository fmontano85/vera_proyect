<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;

/** Gestion de usuarios del tenant: solo admin (seccion 3.2). */
class UserPolicy
{
    public function gestionar(User $user): bool
    {
        return $user->hasRole('admin');
    }
}
