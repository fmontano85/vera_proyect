<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\TipoAlerta;
use App\Mail\ResumenSeguimientosPendientes;
use App\Models\Alert;
use App\Models\Subject;
use App\Models\User;
use App\Services\Seguimiento\CalculadoraSeguimiento;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Stancl\Tenancy\Database\Models\Tenant;
use Throwable;

/**
 * Seccion 3.4 (paso 5) / 3.8: resumen de seguimientos pendientes a
 * oficial_cumplimiento + admin del tenant (decision del usuario
 * 2026-09-25). Un correo POR destinatario: una direccion rechazada no
 * bloquea a las demas y nadie ve las direcciones de otros.
 *
 * Idempotente y reintentable (seccion 7):
 * - ShouldBeUnique por tenant: dos envios del mismo tenant no corren a
 *   la vez.
 * - Todo en una transaccion con las alertas bloqueadas: si ningun correo
 *   sale, se revierte y el reintento las vuelve a tomar; enviado_en solo
 *   se marca si al menos un destinatario recibio el correo.
 * - Alertas obsoletas (subject ya atendido despues de la alerta,
 *   desactivado o ya no vencido) se descartan sin notificar: nunca se
 *   avisa de un pendiente que ya no existe. Nunca notificaron a nadie,
 *   asi que borrarlas no pierde rastro de auditoria.
 * - Sin destinatarios, las alertas quedan pendientes; el job diario las
 *   reencola mientras sigan sin enviar.
 */
class SendAlertJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const ROLES_DESTINATARIOS = ['oficial_cumplimiento', 'admin'];

    public function __construct(public readonly string $tenantId)
    {
        $this->onQueue('alerts');
    }

    public function uniqueId(): string
    {
        return $this->tenantId;
    }

    public function handle(): void
    {
        $tenant = Tenant::find($this->tenantId);

        if ($tenant === null) {
            return;
        }

        // No $tenant->run(): no restaura el contexto si el callback lanza,
        // y aqui lanzar es el camino normal para forzar el reintento.
        $anterior = tenant();
        tenancy()->initialize($tenant);

        try {
            DB::transaction(fn () => $this->enviar());
        } finally {
            $anterior !== null ? tenancy()->initialize($anterior) : tenancy()->end();
        }
    }

    private function enviar(): void
    {
        $hoy = app(CalculadoraSeguimiento::class)->hoy()->toDateString();

        $pendientes = Alert::query()
            ->where('tipo', TipoAlerta::SeguimientoPendiente)
            ->whereNull('enviado_en')
            ->lockForUpdate()
            ->with('alertable')
            ->get();

        [$vigentes, $obsoletas] = $pendientes->partition(fn (Alert $alerta) => $this->sigueVigente($alerta, $hoy));

        if ($obsoletas->isNotEmpty()) {
            Alert::whereKey($obsoletas->modelKeys())->delete();
        }

        if ($vigentes->isEmpty()) {
            return;
        }

        $destinatarios = User::query()
            ->where('tenant_id', $this->tenantId)
            ->role(self::ROLES_DESTINATARIOS)
            ->get();

        if ($destinatarios->isEmpty()) {
            Log::warning('Seguimientos pendientes sin destinatario: el tenant no tiene oficial_cumplimiento ni admin.', [
                'tenant_id' => $this->tenantId,
                'alertas' => $vigentes->count(),
            ]);

            return;
        }

        $siguenVencidos = Subject::query()
            ->vencidosAl($hoy)
            ->whereNotIn('id', $vigentes->pluck('alertable_id'))
            ->count();

        $entregados = $this->enviarA($destinatarios, new Collection($vigentes->values()->all()), $siguenVencidos);

        Alert::whereKey($vigentes->modelKeys())->update(['enviado_en' => now()]);

        // Seccion 9: constancia de alertas enviadas ante revision de la UIF.
        activity()
            ->event('alertas_enviadas')
            ->withProperties([
                'tenant_id' => $this->tenantId,
                'alert_ids' => $vigentes->modelKeys(),
                'subject_ids' => $vigentes->pluck('alertable_id')->all(),
                'siguen_vencidos' => $siguenVencidos,
                'destinatarios' => $entregados,
                'descartadas_obsoletas' => $obsoletas->modelKeys(),
            ])
            ->log('Resumen de seguimientos pendientes enviado');
    }

    /**
     * Vigente = el subject sigue activo y vencido, y no se ha marcado un
     * seguimiento despues de crearse la alerta.
     */
    private function sigueVigente(Alert $alerta, string $hoy): bool
    {
        $subject = $alerta->alertable;

        if (! $subject instanceof Subject || ! $subject->activo || $subject->proximo_seguimiento_en === null) {
            return false;
        }

        if ($subject->proximo_seguimiento_en->toDateString() > $hoy) {
            return false;
        }

        return $subject->ultimo_seguimiento_en === null
            || $subject->ultimo_seguimiento_en->lt($alerta->created_at);
    }

    /**
     * @param  Collection<int, User>  $destinatarios
     * @param  Collection<int, Alert>  $alertas
     * @return list<int> ids de los usuarios a los que si se les entrego
     */
    private function enviarA(Collection $destinatarios, Collection $alertas, int $siguenVencidos): array
    {
        $entregados = [];
        $ultimoError = null;

        foreach ($destinatarios as $usuario) {
            try {
                Mail::to($usuario)->send(new ResumenSeguimientosPendientes($alertas, $siguenVencidos));
                $entregados[] = $usuario->id;
            } catch (Throwable $e) {
                $ultimoError = $e;
                Log::error('No se pudo enviar el resumen de seguimientos a un destinatario.', [
                    'tenant_id' => $this->tenantId,
                    'user_id' => $usuario->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Ninguno recibio: se revierte la transaccion y el job se reintenta.
        if ($entregados === []) {
            throw $ultimoError ?? new RuntimeException('No se pudo enviar el resumen de seguimientos a ningun destinatario.');
        }

        return $entregados;
    }
}
