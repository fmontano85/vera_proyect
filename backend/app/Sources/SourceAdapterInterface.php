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
     * $diasAtras (2026-09-25, decimo bloque): el filtro temporal se aplica
     * en origen - el proveedor solo devuelve paginas dentro de la ventana.
     * null = sin filtro. VentanaTemporal/FetchArticleJob siguen como
     * segunda capa (la fecha del proveedor no es la de publicacion).
     *
     * 'metadata': lo que el proveedor reporta sobre como interpreto la
     * query (null si no reporta nada) - se guarda en search_runs para
     * auditoria.
     *
     * @return array{
     *     resultados: array<int, array{url: string, titulo: ?string, descripcion: ?string, medio: ?string, fecha: ?string}>,
     *     costo: float|null,
     *     metadata: array<string, mixed>|null
     * }
     */
    public function buscar(string $query, ?int $diasAtras = null): array;
}
