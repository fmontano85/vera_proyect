<?php

declare(strict_types=1);

namespace App\Actions\Subjects;

use App\Models\Subject;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Listado de la lista de vigilancia (pantalla de gestion): activos por
 * defecto, filtros por estado y nivel, busqueda por nombre o alias.
 * Orden por nombre con desempate por id: entre homonimos (comunes en este
 * dominio) la paginacion no repite ni pierde filas.
 */
class ListarSubjects
{
    /**
     * @param  array{buscar?: ?string, nivel?: ?string, estado?: ?string}  $filtros
     * @return LengthAwarePaginator<int, Subject>
     */
    public function handle(array $filtros): LengthAwarePaginator
    {
        $estado = $filtros['estado'] ?? 'activos';
        $buscar = trim($filtros['buscar'] ?? '');
        $nivel = $filtros['nivel'] ?? null;

        return Subject::query()
            ->withCount('aliases')
            ->with('ultimoSeguimientoUsuario')
            ->when($estado !== 'todos', fn ($q) => $q->where('activo', $estado === 'activos'))
            ->when($nivel !== null, fn ($q) => $nivel === 'sin_nivel'
                ? $q->whereNull('nivel_riesgo')
                : $q->where('nivel_riesgo', $nivel))
            ->when($buscar !== '', fn ($q) => $q->buscarNombreOAlias($buscar))
            ->orderBy('nombre_canonico')
            ->orderBy('id')
            ->paginate();
    }
}
