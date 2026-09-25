<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\TipoAlerta;
use App\Models\Alert;
use App\Models\Subject;
use App\Services\Seguimiento\CalculadoraSeguimiento;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Stancl\Tenancy\Database\Models\Tenant;

/**
 * Seccion 3.8 del CLAUDE.md raiz (Fase 2): unico job programado de la
 * agenda de seguimiento. Solo consulta la BD propia - CERO llamadas a
 * Brave o Anthropic (seccion 7: ningun job programado llama a servicios
 * externos de pago).
 *
 * Por tenant (tenancy inicializada por runForMultiple, seccion 6):
 * - Una alerta por CICLO de seguimiento: si el subject ya tiene una
 *   alerta creada despues de su ultimo seguimiento realizado, no se crea
 *   otra aunque su fecha se haya recalculado y siga vencida (cambio de
 *   nivel o de dias del tenant). Tras marcar "Seguimiento realizado"
 *   empieza un ciclo nuevo. El unique de alerts (subject + vencimiento)
 *   queda como red de seguridad ante corridas concurrentes.
 * - Encola el envio si quedan alertas sin enviar, sean nuevas o de
 *   corridas anteriores (ej. el tenant no tenia destinatarios).
 */
class DetectarSeguimientosVencidosJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct()
    {
        $this->onQueue('alerts');
    }

    public function handle(): void
    {
        $calculadora = app(CalculadoraSeguimiento::class);

        tenancy()->runForMultiple(null, function (Tenant $tenant) use ($calculadora) {
            Subject::query()
                ->vencidosAl($calculadora->hoy()->toDateString())
                ->each(function (Subject $subject) {
                    if ($this->yaAlertadoEnEsteCiclo($subject)) {
                        return;
                    }

                    Alert::firstOrCreate(
                        [
                            'tipo' => TipoAlerta::SeguimientoPendiente,
                            'alertable_type' => $subject->getMorphClass(),
                            'alertable_id' => $subject->id,
                            'vencimiento' => $subject->proximo_seguimiento_en->toDateString(),
                        ],
                        ['canal' => 'correo'],
                    );
                });

            $pendientesDeEnvio = Alert::query()
                ->where('tipo', TipoAlerta::SeguimientoPendiente)
                ->whereNull('enviado_en')
                ->exists();

            if ($pendientesDeEnvio) {
                SendAlertJob::dispatch($tenant->getTenantKey());
            }
        });
    }

    private function yaAlertadoEnEsteCiclo(Subject $subject): bool
    {
        return Alert::query()
            ->where('tipo', TipoAlerta::SeguimientoPendiente)
            ->where('alertable_type', $subject->getMorphClass())
            ->where('alertable_id', $subject->id)
            ->when($subject->ultimo_seguimiento_en, fn ($q, $ultimo) => $q->where('created_at', '>', $ultimo))
            ->exists();
    }
}
