<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\SanctionEntryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Scout\Searchable;

/**
 * Catalogo global (sin BelongsToTenant), igual que SanctionList.
 */
#[Fillable(['sanction_list_id', 'external_id', 'nombre', 'aliases', 'tipo', 'programa', 'pais', 'raw_json'])]
class SanctionEntry extends Model
{
    /** @use HasFactory<SanctionEntryFactory> */
    use HasFactory, Searchable;

    protected function casts(): array
    {
        return [
            'aliases' => 'array',
            'raw_json' => 'array',
        ];
    }

    /** Indice global 'sanction_entries' (sin tenant): las listas son las mismas para todos. */
    public function toSearchableArray(): array
    {
        return [
            'id' => $this->id,
            'nombre' => $this->nombre,
            'aliases' => $this->aliases ?? [],
        ];
    }

    public function sanctionList(): BelongsTo
    {
        return $this->belongsTo(SanctionList::class);
    }
}
