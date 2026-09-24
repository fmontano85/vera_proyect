<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OrigenMention;
use Database\Factories\MentionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Global (sin tenant_id) - ver comentario en la migracion.
 *
 * article_id nullable desde la seccion 3.7 (captura manual, sin Article
 * detras - el fetch fallo, por eso es GAP). search_result_id es el
 * enlace confiable para "las menciones de este resultado" sin depender
 * de article_id; creado_por/origen fuera de Fillable, los asigna la
 * Action correspondiente (ExtractEntitiesJob o CapturaManual), no
 * create() generico.
 */
#[Fillable(['article_id', 'search_result_id', 'nombre_extraido', 'rol', 'delitos', 'confianza', 'fecha_hecho', 'resumen'])]
class Mention extends Model
{
    /** @use HasFactory<MentionFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'delitos' => 'array',
            'confianza' => 'decimal:3',
            'origen' => OrigenMention::class,
            'fecha_hecho' => 'date',
        ];
    }

    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }

    public function searchResult(): BelongsTo
    {
        return $this->belongsTo(SearchResult::class);
    }

    public function creadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creado_por');
    }

    /**
     * "match" es palabra reservada de PHP 8+ solo para NOMBRES DE CLASE
     * (por eso el modelo se llama MentionMatch, ver su propio comentario) -
     * como metodo es valida. matches.mention_id no es unico por si solo
     * (en teoria varios subjects distintos podrian matchear la misma
     * mention), pero en el uso real de este controlador siempre se carga
     * junto con un search_result ya acotado a un solo subject.
     */
    public function match(): HasOne
    {
        return $this->hasOne(MentionMatch::class);
    }
}
