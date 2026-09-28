<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

/**
 * Reporte de auditoria generado en segundo plano (seccion 1, punto 5).
 * Su archivo vive en el disco de archivos del tenant, bajo
 * tenants/{tenant_id}/reportes/.
 *
 * @property string $tipo
 * @property string $formato
 * @property string $estado
 * @property array<string, mixed> $parametros
 */
#[Fillable(['tipo', 'formato', 'parametros', 'generado_por'])]
class Report extends Model
{
    use BelongsToTenant;

    public const TIPOS = ['ficha_persona', 'actividad_periodo', 'lista_por_riesgo'];

    public const FORMATOS = ['pdf', 'csv'];

    protected $attributes = ['estado' => 'pendiente'];

    protected function casts(): array
    {
        return [
            'parametros' => 'array',
            'generado_en' => 'datetime',
        ];
    }

    /** Personas incluidas (tabla report_subject): borrar una persona borra estos reportes. */
    public function subjects(): BelongsToMany
    {
        return $this->belongsToMany(Subject::class, 'report_subject');
    }

    public function generadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generado_por');
    }

    public static function disco(): \Illuminate\Contracts\Filesystem\Filesystem
    {
        return \Illuminate\Support\Facades\Storage::disk(config('vera.evidencia_manual_disk'));
    }
}
