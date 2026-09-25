<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\FechaSinHora;
use App\Enums\TipoAlerta;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

/**
 * Seccion 3.3/3.8 del CLAUDE.md raiz. Una fila por subject y vencimiento
 * (unique en la migracion = idempotencia de DetectarSeguimientosVencidosJob).
 */
#[Fillable(['tipo', 'alertable_type', 'alertable_id', 'vencimiento', 'canal', 'enviado_en'])]
class Alert extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'tipo' => TipoAlerta::class,
            'vencimiento' => FechaSinHora::class,
            'enviado_en' => 'datetime',
        ];
    }

    public function alertable(): MorphTo
    {
        return $this->morphTo();
    }
}
