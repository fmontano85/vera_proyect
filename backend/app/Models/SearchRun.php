<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\DerivesTenantFromSubject;
use Database\Factories\SearchRunFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

#[Fillable(['subject_id', 'source_id', 'query', 'metadata_query', 'tags', 'dias_atras', 'resultados', 'costo'])]
class SearchRun extends Model
{
    /** @use HasFactory<SearchRunFactory> */
    use BelongsToTenant, DerivesTenantFromSubject, HasFactory;

    protected function casts(): array
    {
        return [
            'tags' => 'array',
            'metadata_query' => 'array',
            'resultados' => 'array',
            'costo' => 'decimal:4',
        ];
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }
}
