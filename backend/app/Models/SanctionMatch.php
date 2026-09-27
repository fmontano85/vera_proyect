<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\DerivesTenantFromSubject;
use Database\Factories\SanctionMatchFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

/**
 * tenant_id fuera de Fillable: BelongsToTenant/DerivesTenantFromSubject
 * lo asignan al crear. resuelto_por/resuelto_en tampoco son fillable por
 * request: la resolucion humana (seccion 1, principio no negociable) se
 * hace por una accion dedicada, no por el create() generico.
 */
#[Fillable(['subject_id', 'sanction_entry_id', 'score', 'estado'])]
class SanctionMatch extends Model
{
    /** @use HasFactory<SanctionMatchFactory> */
    use BelongsToTenant, DerivesTenantFromSubject, HasFactory, LogsActivity;

    /** Toda resolucion queda auditada (seccion 7 del CLAUDE.md raiz). */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['estado', 'resuelto_por'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    protected function casts(): array
    {
        return [
            'score' => 'decimal:2',
            'resuelto_en' => 'datetime',
        ];
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function sanctionEntry(): BelongsTo
    {
        return $this->belongsTo(SanctionEntry::class);
    }

    public function resueltoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resuelto_por');
    }
}
