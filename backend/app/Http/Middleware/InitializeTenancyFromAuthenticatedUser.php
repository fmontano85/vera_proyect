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

        /**
         * superadmin gestiona tenants/planes/fuentes globales (seccion
         * 3.2), no datos de negocio de un tenant - no tiene nada que
         * hacer en una ruta protegida por este middleware. Dejarlo pasar
         * "sin tenant inicializado" seria peor que bloquearlo: el global
         * scope de tenant_id no filtra si tenancy no esta inicializada,
         * asi que veria/tocaria datos de TODOS los tenants mezclados.
         * Sus propias rutas (Fase 3, panel superadmin) no llevan este
         * middleware.
         */
        abort_if($user->hasRole('superadmin'), 403, 'Las rutas de tenant no son para superadmin.');

        abort_if($user->tenant_id === null, 403, 'Usuario sin tenant asignado.');

        $tenant = $user->tenant;

        abort_if($tenant === null, 403, 'Tenant no encontrado.');

        tenancy()->initialize($tenant);

        return $next($request);
    }
}
