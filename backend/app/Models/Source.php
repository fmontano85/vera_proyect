<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\SourceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Catalogo global (no usa BelongsToTenant, no tiene tenant_id): sources
 * la administra superadmin, no cada tenant (seccion 3.3 del CLAUDE.md
 * raiz). Si en el futuro hace falta una fuente privada de un tenant,
 * eso se modela cuando exista esa necesidad real, con su propio scope.
 */
#[Fillable(['nombre', 'tipo', 'config', 'activo'])]
class Source extends Model
{
    /** @use HasFactory<SourceFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'config' => 'array',
            'activo' => 'boolean',
        ];
    }
}
