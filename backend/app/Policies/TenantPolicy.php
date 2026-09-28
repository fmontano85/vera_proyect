<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;

/**
 * Gestion de tenants (panel de superadmin, primera pantalla - seccion 3.2
 * del CLAUDE.md raiz: "superadmin: gestion de tenants, planes,
 * facturacion, fuentes globales"). Tenant es un modelo de stancl/tenancy
 * (namespace Stancl\Tenancy\...), no de la app - por eso el registro es
 * explicito en AppServiceProvider::boot() via Gate::policy(), la
 * convencion de autodescubrimiento de Laravel no lo encuentra solo.
 */
class TenantPolicy
{
    public function gestionar(User $user): bool
    {
        return $user->hasRole('superadmin');
    }
}
