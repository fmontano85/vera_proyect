<?php

declare(strict_types=1);

namespace App\Support;

use Stancl\Tenancy\Database\Models\Tenant;

/**
 * Ajustes de proteccion de datos por tenant (seccion 3.9, puntos 2 y 3).
 *
 * Se guardan como atributos virtuales de stancl/tenancy (columna JSON
 * 'data'), igual que en la practica ya pasa con name y
 * sanciones_habilitado: el Tenant de stancl solo trata 'id' como columna
 * real. Los defaults viven aqui, no en la BD. Nunca filtrar por ellos en
 * SQL: cargar los tenants y filtrar en PHP.
 */
final class ConfiguracionTenant
{
    /** Art. 26, Ley Contra el Lavado de Dinero y de Activos (Decreto 426). */
    public const RETENCION_MINIMA_ANIOS = 15;

    public const RETENCION_MAXIMA_ANIOS = 100;

    public static function retencionAnios(Tenant $tenant): int
    {
        return max(self::RETENCION_MINIMA_ANIOS, (int) ($tenant->retencion_anios ?? self::RETENCION_MINIMA_ANIOS));
    }

    public static function depuracionHabilitada(Tenant $tenant): bool
    {
        return (bool) ($tenant->depuracion_habilitada ?? false);
    }
}
