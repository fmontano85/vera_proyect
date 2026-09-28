<?php

declare(strict_types=1);

namespace App\Models;

use Spatie\Activitylog\Models\Activity as ActivityBase;
use Stancl\Tenancy\Database\Models\Tenant;

/**
 * Registro de la bitacora (activity_log) con su tenant (seccion 3.9, punto
 * 7). Registrado en config('activitylog.activity_model').
 *
 * NO usa BelongsToTenant a proposito: el superadmin consulta registros de
 * todos los tenants y ese scope falla abierto fuera de tenancy (seccion
 * 6). Toda consulta filtra tenant_id explicito.
 *
 * @property string|null $tenant_id
 */
class Activity extends ActivityBase
{
    protected static function booted(): void
    {
        static::creating(function (Activity $actividad) {
            $actividad->tenant_id ??= self::tenantDe($actividad);
        });
    }

    /**
     * Orden: tenancy activa (request de tenant, jobs con $tenant->run());
     * accion del superadmin sobre un tenant; tenant del causante (login,
     * logout y cuenta propia corren fuera del grupo 'tenant').
     */
    private static function tenantDe(Activity $actividad): ?string
    {
        if (tenancy()->initialized) {
            return (string) tenant()->getTenantKey();
        }

        if ($actividad->subject_type === Tenant::class) {
            return (string) $actividad->subject_id;
        }

        $causante = $actividad->causer;

        return $causante instanceof User ? $causante->tenant_id : null;
    }
}
