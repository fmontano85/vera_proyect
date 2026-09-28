<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Terminos de servicio o contrato de encargo (seccion 3.9, punto 1).
 * Global, sin tenant_id: lo redacta el superadmin para toda la plataforma.
 * version null = borrador. Publicado = inmutable.
 *
 * @property string $tipo
 * @property int|null $version
 */
#[Fillable(['tipo', 'titulo', 'contenido', 'creado_por'])]
class DocumentoLegal extends Model
{
    use LogsActivity;

    public const TIPOS = ['terminos', 'contrato_encargo'];

    protected $table = 'documentos_legales';

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'publicado_en' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        // Sin 'contenido': puede ser largo y la version publicada ya es inmutable en su propia fila.
        return LogOptions::defaults()
            ->logOnly(['tipo', 'titulo', 'version', 'publicado_en'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    public function publicadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'publicado_por');
    }

    public function aceptaciones(): HasMany
    {
        return $this->hasMany(AceptacionDocumento::class);
    }

    public function esBorrador(): bool
    {
        return $this->publicado_en === null;
    }

    /** @param  Builder<self>  $query */
    public function scopePublicados(Builder $query): void
    {
        $query->whereNotNull('publicado_en');
    }

    /**
     * La version publicada mas alta de cada tipo.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, self>
     */
    public static function vigentes(): \Illuminate\Database\Eloquent\Collection
    {
        return self::query()->publicados()
            ->where('version', fn ($q) => $q->from('documentos_legales as d2')
                ->selectRaw('max(d2.version)')
                ->whereColumn('d2.tipo', 'documentos_legales.tipo'))
            ->orderBy('tipo')
            ->get();
    }
}
