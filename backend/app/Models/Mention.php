<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\MentionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Global (sin tenant_id) - ver comentario en la migracion.
 */
#[Fillable(['article_id', 'nombre_extraido', 'rol', 'delitos', 'confianza'])]
class Mention extends Model
{
    /** @use HasFactory<MentionFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'delitos' => 'array',
            'confianza' => 'decimal:3',
        ];
    }

    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }
}
