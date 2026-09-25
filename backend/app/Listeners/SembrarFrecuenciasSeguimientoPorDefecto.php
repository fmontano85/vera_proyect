<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Models\FrecuenciaSeguimiento;
use Stancl\Tenancy\Events\TenantCreated;

/**
 * Seccion 3.8: todo tenant nuevo arranca con los dias por nivel de
 * riesgo por defecto (FrecuenciaSeguimiento::DEFAULTS); el admin los
 * cambia despues. Mismo patron que SembrarTagsBusquedaPorDefecto.
 */
class SembrarFrecuenciasSeguimientoPorDefecto
{
    /**
     * run() restaura el tenant que estuviera inicializado antes: con
     * initialize()/end() quien crea un tenant dentro del contexto de otro
     * (vera:demo, seeders, tests) quedaba sin tenancy - y BelongsToTenant
     * falla abierto (seccion 6).
     */
    public function handle(TenantCreated $event): void
    {
        $event->tenant->run(fn () => FrecuenciaSeguimiento::sembrarDefaults());
    }
}
