<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\DerivesTenantFromSubject;
use Database\Factories\MentionMatchFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

/**
 * Modelo para la tabla 'matches' (seccion 3.3 del CLAUDE.md raiz). Se
 * llama MentionMatch, no Match, porque "match" es palabra reservada en
 * PHP 8+ y no puede ser nombre de clase.
 *
 * tenant_id, estado y las columnas resuelto_ / propuesta_ fuera de
 * Fillable: se derivan del subject (DerivesTenantFromSubject) o se
 * asignan por las acciones dedicadas ProponerResolucion/ResolverMatch
 * (seccion 1, principio no negociable: "el sistema propone, el analista
 * resuelve"), no por create()/update() generico.
 *
 * Flujo de resolucion en dos pasos (seccion 3.2): las columnas
 * propuesta_ son la sugerencia del analista (no cambian 'estado');
 * 'estado' y las columnas resuelto_ son la decision final, que solo pone
 * oficial_cumplimiento/admin via ResolverMatch - puede coincidir con la
 * propuesta o no.
 */
#[Fillable(['mention_id', 'subject_id', 'score_meilisearch'])]
class MentionMatch extends Model
{
    /** @use HasFactory<MentionMatchFactory> */
    use BelongsToTenant, DerivesTenantFromSubject, HasFactory, LogsActivity;

    protected $table = 'matches';

    protected function casts(): array
    {
        return [
            'score_meilisearch' => 'decimal:2',
            'resuelto_en' => 'datetime',
            'propuesta_en' => 'datetime',
        ];
    }

    /**
     * Auditoria obligatoria de toda resolucion de coincidencia (seccion 7
     * del CLAUDE.md raiz, regla no negociable) - tambien se audita la
     * propuesta del analista, no solo la resolucion final: sirve para ver
     * despues si el oficial confirmo o discrepo de lo que se sugirio.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['estado', 'resuelto_por', 'propuesta_estado', 'propuesta_por'])
            ->logOnlyDirty();
    }

    public function mention(): BelongsTo
    {
        return $this->belongsTo(Mention::class);
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function resueltoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resuelto_por');
    }

    public function propuestaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'propuesta_por');
    }
}
