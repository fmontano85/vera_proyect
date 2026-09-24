<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EstadoSearchResult;
use App\Enums\GapMotivo;
use App\Models\Concerns\DerivesTenantFromSubject;
use Database\Factories\SearchResultFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

/**
 * Flujo bajo demanda (seccion 3.7 del CLAUDE.md raiz): un registro por
 * cada resultado que devuelve Brave. Las columnas estado, descartado_por,
 * descartado_en, article_id y evidencia_manual_path quedan fuera de
 * Fillable: se asignan por las Actions dedicadas en App\Actions\SearchResults,
 * no por create()/update() generico - mismo criterio que MentionMatch con
 * sus columnas resuelto_ y propuesta_.
 */
#[Fillable(['search_run_id', 'subject_id', 'url', 'url_hash', 'titulo', 'snippet', 'medio', 'fecha_brave'])]
class SearchResult extends Model
{
    /** @use HasFactory<SearchResultFactory> */
    use BelongsToTenant, DerivesTenantFromSubject, HasFactory;

    protected function casts(): array
    {
        return [
            'estado' => EstadoSearchResult::class,
            'gap_motivo' => GapMotivo::class,
            'fecha_brave' => 'datetime',
            'descartado_en' => 'datetime',
        ];
    }

    public function searchRun(): BelongsTo
    {
        return $this->belongsTo(SearchRun::class);
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }

    public function mentions(): HasMany
    {
        return $this->hasMany(Mention::class);
    }

    public function descartadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'descartado_por');
    }
}
