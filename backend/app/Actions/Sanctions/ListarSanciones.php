<?php

declare(strict_types=1);

namespace App\Actions\Sanctions;

use App\Models\SanctionMatch;
use App\Services\Sanctions\SerializadorSancion;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/** Listado de hallazgos de sanciones: pendientes por defecto, filtro opcional por subject. */
class ListarSanciones
{
    public function __construct(private readonly SerializadorSancion $serializador) {}

    /**
     * @param  array{estado?: ?string, subject_id?: ?int}  $filtros
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function handle(array $filtros): LengthAwarePaginator
    {
        return SanctionMatch::query()
            ->with(SerializadorSancion::RELACIONES)
            ->when(($filtros['estado'] ?? 'pendiente') === 'pendiente', fn ($q) => $q->where('estado', 'pendiente'))
            ->when(isset($filtros['subject_id']), fn ($q) => $q->where('subject_id', $filtros['subject_id']))
            ->orderByDesc('score')
            ->orderByDesc('id')
            ->paginate()
            ->through(fn (SanctionMatch $m) => $this->serializador->serializar($m));
    }
}
