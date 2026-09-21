<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\SanctionListFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Catalogo global (sin BelongsToTenant): las listas de sanciones son las
 * mismas para todos los tenants (seccion 3.3 del CLAUDE.md raiz).
 */
#[Fillable(['codigo', 'version', 'fecha_importacion'])]
class SanctionList extends Model
{
    /** @use HasFactory<SanctionListFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'fecha_importacion' => 'datetime',
        ];
    }

    public function entries(): HasMany
    {
        return $this->hasMany(SanctionEntry::class);
    }
}
