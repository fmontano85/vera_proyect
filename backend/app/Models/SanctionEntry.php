<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\SanctionEntryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Catalogo global (sin BelongsToTenant), igual que SanctionList.
 */
#[Fillable(['sanction_list_id', 'external_id', 'nombre', 'aliases', 'tipo', 'programa', 'pais', 'raw_json'])]
class SanctionEntry extends Model
{
    /** @use HasFactory<SanctionEntryFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'aliases' => 'array',
            'raw_json' => 'array',
        ];
    }

    public function sanctionList(): BelongsTo
    {
        return $this->belongsTo(SanctionList::class);
    }
}
