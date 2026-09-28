<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

/**
 * Aceptacion de una version de un documento legal por un tenant (seccion
 * 3.9, punto 1): quien, cuando y desde que IP. Auditada.
 */
#[Fillable(['documento_legal_id', 'aceptado_por', 'aceptado_en', 'ip'])]
class AceptacionDocumento extends Model
{
    use BelongsToTenant, LogsActivity;

    protected $table = 'aceptaciones_documentos';

    protected function casts(): array
    {
        return [
            'aceptado_en' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['documento_legal_id', 'aceptado_por', 'aceptado_en', 'ip']);
    }

    public function documento(): BelongsTo
    {
        return $this->belongsTo(DocumentoLegal::class, 'documento_legal_id');
    }

    public function aceptadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'aceptado_por');
    }
}
