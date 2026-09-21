<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ExtractionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['article_id', 'modelo', 'json_resultado', 'confianza', 'tokens_in', 'tokens_out', 'costo'])]
class Extraction extends Model
{
    /** @use HasFactory<ExtractionFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'json_resultado' => 'array',
            'confianza' => 'decimal:3',
            'costo' => 'decimal:4',
        ];
    }

    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }
}
