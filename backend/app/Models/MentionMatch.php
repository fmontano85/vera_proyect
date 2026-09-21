<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\DerivesTenantFromSubject;
use Database\Factories\MentionMatchFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

/**
 * Modelo para la tabla 'matches' (seccion 3.3 del CLAUDE.md raiz). Se
 * llama MentionMatch, no Match, porque "match" es palabra reservada en
 * PHP 8+ y no puede ser nombre de clase.
 *
 * tenant_id/resuelto_por/resuelto_en fuera de Fillable: se derivan del
 * subject (DerivesTenantFromSubject) o se asignan por una accion de
 * resolucion dedicada (seccion 1, principio no negociable), no por
 * create() generico.
 */
#[Fillable(['mention_id', 'subject_id', 'score_meilisearch', 'estado'])]
class MentionMatch extends Model
{
    /** @use HasFactory<MentionMatchFactory> */
    use BelongsToTenant, DerivesTenantFromSubject, HasFactory;

    protected $table = 'matches';

    protected function casts(): array
    {
        return [
            'score_meilisearch' => 'decimal:2',
            'resuelto_en' => 'datetime',
        ];
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
}
