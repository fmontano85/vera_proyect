<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ConfiguracionSancionesFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Fila unica global (id=1), sin tenant_id: el modo de descarga de la
 * lista OFAC es una sola configuracion para todo el sistema, no por
 * tenant (ver migracion). Solo el superadmin la cambia
 * (App\Policies\TenantPolicy::gestionar).
 */
#[Fillable(['modo_descarga_ofac', 'actualizado_por'])]
class ConfiguracionSanciones extends Model
{
    /** @use HasFactory<ConfiguracionSancionesFactory> */
    use HasFactory, LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['modo_descarga_ofac'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    public function actualizadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actualizado_por');
    }

    /** Siempre la misma fila (id=1) - la crea con el default si no existe todavia. */
    public static function actual(): self
    {
        return self::firstOrCreate(['id' => 1], ['modo_descarga_ofac' => 'automatico']);
    }

    public static function modoDescarga(): string
    {
        return self::actual()->modo_descarga_ofac;
    }
}
