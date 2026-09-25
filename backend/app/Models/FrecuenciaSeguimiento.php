<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

/**
 * Dias de seguimiento por nivel de riesgo, por tenant (seccion 3.8 del
 * CLAUDE.md raiz). Cambiarlos afecta la agenda de toda la lista de
 * vigilancia del tenant - por eso queda auditado (seccion 7).
 */
#[Fillable(['nivel_riesgo', 'dias'])]
class FrecuenciaSeguimiento extends Model
{
    use BelongsToTenant, LogsActivity;

    protected $table = 'frecuencias_seguimiento';

    /**
     * Defaults confirmados por el usuario 2026-09-25 (provisionales hasta
     * verificar la frecuencia minima del instructivo UIF - sin piso
     * regulatorio por ahora, solo 1..365).
     */
    public const DEFAULTS = [
        'alto' => 30,
        'medio' => 90,
        'bajo' => 180,
        'sin_nivel' => 180,
    ];

    public const NIVELES = ['alto', 'medio', 'bajo', 'sin_nivel'];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['nivel_riesgo', 'dias'])
            ->logOnlyDirty();
    }

    protected function casts(): array
    {
        return [
            'dias' => 'integer',
        ];
    }

    /**
     * Requiere tenancy() inicializada (BelongsToTenant). Idempotente: no
     * pisa valores que el tenant ya haya cambiado.
     */
    public static function sembrarDefaults(): void
    {
        foreach (self::DEFAULTS as $nivel => $dias) {
            self::firstOrCreate(['nivel_riesgo' => $nivel], ['dias' => $dias]);
        }
    }
}
