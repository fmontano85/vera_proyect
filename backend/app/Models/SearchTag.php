<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\SearchTagFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

/**
 * Catalogo de tags de busqueda por tenant (sesion posterior a la 3.7,
 * 2026-09-24) - ver migracion create_search_tags_table y
 * Listeners\SembrarTagsBusquedaPorDefecto para los defaults sembrados al
 * crear un tenant.
 */
#[Fillable(['nombre', 'activo'])]
class SearchTag extends Model
{
    /** @use HasFactory<SearchTagFactory> */
    use BelongsToTenant, HasFactory;

    protected function casts(): array
    {
        return [
            'activo' => 'boolean',
        ];
    }
}
