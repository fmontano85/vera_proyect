<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Subject;
use App\Services\Sanctions\CruceSanciones;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Stancl\Tenancy\Contracts\Tenant;

/**
 * Cruza todos los subjects activos de todos los tenants contra las listas
 * de sanciones (seccion 3.4). Solo usa la BD y Meilisearch propios: cero
 * llamadas a servicios de pago (seccion 7). Idempotente.
 *
 * Se recorre por tenant con runForMultiple(): SanctionMatch usa
 * BelongsToTenant y sin contexto inicializado fallaria abierto.
 */
class MatchSanctionsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct()
    {
        $this->onQueue('matching');
    }

    public function handle(CruceSanciones $cruce = new CruceSanciones): void
    {
        tenancy()->runForMultiple(null, function (Tenant $tenant) use ($cruce) {
            Subject::query()->where('activo', true)->with('aliases')->each(
                fn (Subject $subject) => $cruce->cruzar($subject)
            );
        });
    }
}
