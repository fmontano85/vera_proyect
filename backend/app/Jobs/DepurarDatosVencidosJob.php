<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\ProteccionDatos\BorrarSubject;
use App\Models\Subject;
use App\Support\ConfiguracionTenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Stancl\Tenancy\Contracts\Tenant;
use Throwable;

/**
 * Depuracion por plazo de retencion (seccion 3.9, punto 3). Solo en
 * tenants donde el superadmin la habilito (apagada por defecto); borra las
 * personas inactivas cuya desactivacion es anterior al plazo del tenant
 * (minimo 15 anios). Las activas nunca se tocan.
 *
 * Solo BD propia, Storage y el indice propio de Meilisearch: ningun
 * servicio de pago (seccion 7). Idempotente: lo que no se pudo borrar hoy
 * se reintenta manana; un fallo en una persona no detiene a las demas.
 */
class DepurarDatosVencidosJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Personas por lote (publico para probar el recorrido con lotes chicos). */
    public int $lote = 200;

    /** La primera corrida sobre un tenant grande borra miles: no el default de 60 s de Horizon. */
    public int $timeout = 3600;

    public function __construct()
    {
        $this->onQueue('imports');
    }

    public function handle(BorrarSubject $borrar): void
    {
        tenancy()->runForMultiple(null, function (Tenant $tenant) use ($borrar) {
            if (! ConfiguracionTenant::depuracionHabilitada($tenant)) {
                return;
            }

            $anios = ConfiguracionTenant::retencionAnios($tenant);
            $eliminadas = 0;
            $fallidas = 0;

            Subject::query()
                ->where('activo', false)
                ->whereNotNull('desactivado_en')
                ->where('desactivado_en', '<=', now()->subYears($anios))
                // lazyById, no each(): each() pagina por offset y al borrar mientras
                // recorre se salta filas (hallazgo del code-review 2026-09-28).
                ->lazyById($this->lote)
                ->each(function (Subject $subject) use ($borrar, &$eliminadas, &$fallidas) {
                    try {
                        $borrar->handle($subject, BorrarSubject::MOTIVO_PLAZO_DE_RETENCION);
                        $eliminadas++;
                    } catch (Throwable $e) {
                        $fallidas++;
                        Log::error('Depuracion: no se pudo borrar una persona.', ['subject_id' => $subject->id, 'error' => $e->getMessage()]);
                    }
                });

            // Solo cuando paso algo: una entrada diaria vacia por tenant ensuciaria la bitacora.
            if ($eliminadas > 0 || $fallidas > 0) {
                activity()->event('depuracion_ejecutada')
                    ->withProperties(['personas_eliminadas' => $eliminadas, 'fallidas' => $fallidas, 'retencion_anios' => $anios])
                    ->log('Depuración por plazo de retención');
            }
        });
    }
}
