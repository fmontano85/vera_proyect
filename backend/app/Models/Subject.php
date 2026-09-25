<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\FechaSinHora;
use App\Services\Seguimiento\CalculadoraSeguimiento;
use Database\Factories\SubjectFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
#[Fillable(['tipo', 'nombre_canonico', 'documento', 'nivel_riesgo', 'activo', 'frecuencia_seguimiento_dias'])]
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
        // Las fechas de seguimiento no van aqui: marcar un seguimiento
        // realizado tiene su propio evento explicito con la observacion
        // (App\Actions\Seguimiento\MarcarSeguimientoRealizado).
        return LogOptions::defaults()
            ->logOnly(['tipo', 'nombre_canonico', 'documento', 'nivel_riesgo', 'activo', 'frecuencia_seguimiento_dias'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    /**
     * Seccion 3.8: la proxima fecha de seguimiento se mantiene sola - al
     * crear (desde la fecha de alta) y al cambiar nivel, frecuencia o
     * ultimo seguimiento. En 'creating' y no en 'saving' porque
     * BelongsToTenant asigna tenant_id en su propio 'creating', que corre
     * antes (boot de traits); en 'saving' todavia seria null.
     */
    protected static function booted(): void
    {
        static::creating(function (Subject $subject) {
            $subject->proximo_seguimiento_en = app(CalculadoraSeguimiento::class)->calcularProximo($subject);
        });

        static::updating(function (Subject $subject) {
            if ($subject->proximo_seguimiento_en === null
                || $subject->isDirty(['nivel_riesgo', 'frecuencia_seguimiento_dias', 'ultimo_seguimiento_en'])) {
                $subject->proximo_seguimiento_en = app(CalculadoraSeguimiento::class)->calcularProximo($subject);
            }
        });
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

    /**
     * El usuario del ultimo seguimiento se expone solo como {id, name}
     * dentro del bloque 'seguimiento' (CalculadoraSeguimiento::resumen),
     * nunca el modelo User completo (email, etc.).
     */
    protected $hidden = ['ultimoSeguimientoUsuario'];

    protected function casts(): array
    {
        return [
            'activo' => 'boolean',
            'frecuencia_seguimiento_dias' => 'integer',
            'proximo_seguimiento_en' => FechaSinHora::class,
            'ultimo_seguimiento_en' => 'datetime',
        ];
    }

    public function aliases(): HasMany
    {
        return $this->hasMany(SubjectAlias::class);
    }

    public function ultimoSeguimientoUsuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ultimo_seguimiento_por');
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

    /**
     * Solo reindexar cuando cambia algo que esta en toSearchableArray()
     * (o 'activo', que decide si esta en el indice). Sin esto, marcar un
     * seguimiento o cambiar nivel/frecuencia (seccion 3.8) llamaba a
     * Meilisearch sincrono dentro de la transaccion: si Meili estaba
     * caido, no se podia registrar el seguimiento. Los aliases cambian en
     * SubjectAlias, no aqui.
     */
    public function searchIndexShouldBeUpdated(): bool
    {
        return $this->wasRecentlyCreated || $this->wasChanged(['nombre_canonico', 'activo']);
    }

    /**
     * Definicion unica de "seguimiento vencido" (seccion 3.8): subject
     * activo con proximo_seguimiento_en <= hoy. La usan el panel, el job
     * diario y el conteo del correo - asi no pueden divergir.
     *
     * @param  Builder<Subject>  $query
     */
    public function scopeVencidosAl(Builder $query, string $hoy): void
    {
        $query->where('activo', true)
            ->whereNotNull('proximo_seguimiento_en')
            ->where('proximo_seguimiento_en', '<=', $hoy);
    }
}
