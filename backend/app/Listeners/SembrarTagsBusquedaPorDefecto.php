<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Models\SearchTag;
use Stancl\Tenancy\Events\TenantCreated;

/**
 * Busqueda por tags (sesion posterior a la 3.7, 2026-09-24): cada tenant
 * administra su propio catalogo de tags (decision del usuario), pero
 * arranca poblado con los delitos de la seccion 1 del CLAUDE.md raiz para
 * no dejar al analista con una lista vacia el primer dia. El flujo normal
 * deja agregar mas desde la pantalla de busqueda por tags.
 */
class SembrarTagsBusquedaPorDefecto
{
    private const TAGS_DEFAULT = [
        'hurto',
        'estafa',
        'extorsion',
        'captura',
        'condena',
        'lavado de dinero',
        'narcotrafico',
        'corrupcion',
    ];

    public function handle(TenantCreated $event): void
    {
        // run() restaura el tenant previo (ver SembrarFrecuenciasSeguimientoPorDefecto).
        $event->tenant->run(function () {
            foreach (self::TAGS_DEFAULT as $nombre) {
                SearchTag::firstOrCreate(['nombre' => $nombre], ['activo' => true]);
            }
        });
    }
}
