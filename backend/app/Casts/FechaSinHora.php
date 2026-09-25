<?php

declare(strict_types=1);

namespace App\Casts;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Database\Eloquent\SerializesCastableAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Fecha de calendario sin hora, guardada siempre como 'Y-m-d'.
 *
 * El cast 'date' de Eloquent guarda con getDateFormat() ('Y-m-d H:i:s'):
 * en SQLite (suite de tests) queda '2026-09-25 00:00:00' y las
 * comparaciones de texto contra '2026-09-25' fallan - el job de
 * vencimientos (seccion 3.8) no veria los del dia y duplicaria alertas.
 * En MariaDB la columna DATE normaliza, pero la app debe comportarse
 * igual en ambos. Un DateTimeInterface se guarda con SU fecha local
 * (ej. un CarbonImmutable en America/El_Salvador), sin convertir a UTC.
 *
 * @implements CastsAttributes<CarbonImmutable, DateTimeInterface|string>
 */
class FechaSinHora implements CastsAttributes, SerializesCastableAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?CarbonImmutable
    {
        if ($value === null) {
            return null;
        }

        return CarbonImmutable::createFromFormat('Y-m-d', substr((string) $value, 0, 10))->startOfDay();
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        return $value instanceof DateTimeInterface
            ? $value->format('Y-m-d')
            : CarbonImmutable::parse((string) $value)->format('Y-m-d');
    }

    /**
     * En el JSON va como 'Y-m-d'. Sin esto Eloquent la serializa como
     * '2026-10-25T00:00:00.000000Z' y un navegador en UTC-6 la muestra
     * como el dia anterior.
     */
    public function serialize(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        // Eloquent pasa el valor crudo de la BD o, si ya se leyo, el objeto.
        return $value instanceof DateTimeInterface ? $value->format('Y-m-d') : substr((string) $value, 0, 10);
    }
}
