<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * VERA resuelve el tenant desde el tenant_id del usuario autenticado
 * (Sanctum), no por dominio/subdominio (ver seccion "Decisiones
 * confirmadas" del CLAUDE.md raiz). Debe correr despues de auth:sanctum.
 */
class InitializeTenancyFromAuthenticatedUser
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        abort_if($user === null, 401);
        abort_if($user->tenant_id === null, 403, 'Usuario sin tenant asignado.');

        $tenant = $user->tenant;

        abort_if($tenant === null, 403, 'Tenant no encontrado.');

        tenancy()->initialize($tenant);

        return $next($request);
    }
}
