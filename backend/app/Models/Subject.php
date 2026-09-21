<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\SubjectFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Scout\Searchable;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

/**
 * tenant_id fuera de Fillable a proposito: BelongsToTenant lo asigna desde
 * tenancy() al crear (ver vendor/stancl/tenancy), nunca desde input del
 * usuario (OWASP A01).
 */
#[Fillable(['tipo', 'nombre_canonico', 'documento', 'nivel_riesgo', 'activo'])]
class Subject extends Model
{
    /** @use HasFactory<SubjectFactory> */
    use BelongsToTenant, HasFactory, LogsActivity, Searchable;

    /**
     * Todo cambio en la lista de vigilancia queda auditado (seccion 7 del
     * CLAUDE.md raiz, regla no negociable) - crear/editar/borrar un
     * Subject se registra en activity_log automaticamente.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['tipo', 'nombre_canonico', 'documento', 'nivel_riesgo', 'activo'])
            ->logOnlyDirty();
    }

    /**
     * Default a nivel de Eloquent, no solo en la migracion: sin esto,
     * $subject->activo queda null en la instancia recien creada cuando
     * el caller no lo manda explicito (Eloquent no relee el default de
     * la columna despues de create()), y shouldBeSearchable(): bool
     * truena con TypeError al recibir null.
     */
    protected $attributes = [
        'activo' => true,
    ];

    protected function casts(): array
    {
        return [
            'activo' => 'boolean',
        ];
    }

    public function aliases(): HasMany
    {
        return $this->hasMany(SubjectAlias::class);
    }

    /**
     * tenant_id como atributo filtrable (seccion 3.1: "toda busqueda
     * filtra por tenant") - MatchMentionsJob siempre consulta con
     * ->where('tenant_id', ...) para no cruzar tenants en el matching.
     */
    public function toSearchableArray(): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'nombre_canonico' => $this->nombre_canonico,
            'aliases' => $this->aliases->pluck('nombre')->all(),
        ];
    }

    public function shouldBeSearchable(): bool
    {
        return $this->activo;
    }
}
