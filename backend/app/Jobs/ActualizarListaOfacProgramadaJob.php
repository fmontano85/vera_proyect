<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\ConfiguracionSanciones;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;

/**
 * Corre semanal (routes/console.php). Solo dispara la importacion+cruce
 * de OFAC si el modo de descarga (panel de superadmin, decision del
 * usuario 2026-09-28) es 'automatico' - en 'manual' la unica forma de
 * actualizar la lista es el boton del superadmin
 * (SuperadminController::actualizarListaSanciones, que dispara el mismo
 * chain sin pasar por este chequeo).
 */
class ActualizarListaOfacProgramadaJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        if (ConfiguracionSanciones::modoDescarga() !== 'automatico') {
            return;
        }

        Bus::chain([
            new ImportSanctionListsJob('ofac_sdn'),
            new MatchSanctionsJob,
        ])->dispatch();
    }
}
