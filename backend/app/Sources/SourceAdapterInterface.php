<?php

declare(strict_types=1);

namespace App\Sources;

interface SourceAdapterInterface
{
    /**
     * Seccion 3.7 del CLAUDE.md raiz (flujo bajo demanda, 2026-09-24):
     * cada resultado trae su metadata (titulo/snippet/medio/fecha) para
     * listarlo en la vista del subject SIN descargarlo - antes solo se
     * devolvia la URL porque se bajaba todo automatico.
     *
     * @return array{
     *     resultados: array<int, array{url: string, titulo: ?string, descripcion: ?string, medio: ?string, fecha: ?string}>,
     *     costo: float|null
     * }
     */
    public function buscar(string $query): array;
}
