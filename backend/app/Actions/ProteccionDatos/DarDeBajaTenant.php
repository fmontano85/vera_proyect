<?php

declare(strict_types=1);

namespace App\Actions\ProteccionDatos;

use App\Models\Activity;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use App\Exceptions\OperacionNoPermitida;
use Stancl\Tenancy\Database\Models\Tenant;
use Throwable;

/**
 * Baja de un tenant al terminar el contrato (seccion 3.9, punto 6): borra
 * todos sus datos, usuarios y evidencia. Exige una exportacion completa
 * en los ultimos 7 dias (devolucion de datos al cliente). Irreversible.
 *
 * No es una sola transaccion: cada persona se borra con BorrarSubject
 * (saca del indice de Meilisearch y borra archivos despues de su commit).
 * Es idempotente: si falla a mitad, repetir la baja continua donde quedo;
 * el tenant se borra al final, asi que hasta entonces sigue visible.
 *
 * En la bitacora solo queda 'tenant_dado_de_baja' (quien y cuando).
 */
class DarDeBajaTenant
{
    public const DIAS_EXPORTACION_VALIDA = 7;

    /** Personas por lote (publico para probar el recorrido con lotes chicos). */
    public int $lote = 200;

    public function __construct(private readonly BorrarSubject $borrarSubject) {}

    public function exportadoRecientemente(Tenant $tenant): bool
    {
        return Activity::query()
            ->where('tenant_id', $tenant->id)
            ->where('event', 'tenant_exportado')
            ->where('created_at', '>=', now()->subDays(self::DIAS_EXPORTACION_VALIDA))
            ->exists();
    }

    /** @return array<string, int> */
    public function handle(Tenant $tenant, User $superadmin): array
    {
        if (! $this->exportadoRecientemente($tenant)) {
            throw new OperacionNoPermitida('Antes de dar de baja el tenant exporta sus datos (en los últimos '.self::DIAS_EXPORTACION_VALIDA.' días).');
        }

        $id = (string) $tenant->id;
        $nombre = $tenant->name;
        $personas = 0;

        $tenant->run(function () use (&$personas) {
            // lazyById, no each(): each() pagina por offset y se salta filas al borrar.
            Subject::query()->lazyById($this->lote)->each(function (Subject $s) use (&$personas) {
                $this->borrarSubject->handle($s, 'baja_de_tenant');
                $personas++;
            });
        });

        $rutas = DB::table('search_results')->where('tenant_id', $id)->whereNotNull('evidencia_manual_path')
            ->pluck('evidencia_manual_path')->all();

        $usuarios = DB::transaction(function () use ($id) {
            // Lo que queda sin persona: busquedas por tags y sus capturas manuales.
            $resultadoIds = DB::table('search_results')->where('tenant_id', $id)->pluck('id');
            $manuales = DB::table('mentions')->where('origen', 'manual')->whereIn('search_result_id', $resultadoIds)->pluck('id');
            DB::table('matches')->where('tenant_id', $id)->orWhereIn('mention_id', $manuales)->delete();
            DB::table('sanction_matches')->where('tenant_id', $id)->delete();
            DB::table('mentions')->whereIn('id', $manuales)->delete();
            DB::table('mentions')->whereIn('search_result_id', $resultadoIds)->update(['search_result_id' => null]);
            DB::table('search_results')->where('tenant_id', $id)->delete();
            DB::table('search_runs')->where('tenant_id', $id)->delete();

            foreach (['alerts', 'search_tags', 'frecuencias_seguimiento', 'aceptaciones_documentos', 'subject_aliases', 'reports'] as $tabla) {
                DB::table($tabla)->where('tenant_id', $id)->delete();
            }

            $userIds = DB::table('users')->where('tenant_id', $id)->pluck('id');
            DB::table('personal_access_tokens')->where('tokenable_type', User::class)->whereIn('tokenable_id', $userIds)->delete();
            DB::table('sessions')->whereIn('user_id', $userIds)->delete();
            DB::table('model_has_roles')->where('model_type', User::class)->whereIn('model_id', $userIds)->delete();
            DB::table('users')->whereIn('id', $userIds)->delete();

            DB::table('activity_log')->where('tenant_id', $id)->delete();
            DB::table('tenants')->where('id', $id)->delete();

            return $userIds->count();
        });

        $this->borrarArchivos($rutas, $id);

        $conteos = ['personas' => $personas, 'usuarios' => $usuarios];
        activity()->performedOn($tenant)->causedBy($superadmin)->event('tenant_dado_de_baja')
            ->withProperties(['tenant' => $nombre, 'eliminado' => $conteos])
            ->log('Tenant dado de baja y sus datos eliminados');

        return $conteos;
    }

    /** @param  list<string>  $rutas */
    private function borrarArchivos(array $rutas, string $tenantId): void
    {
        $disco = Storage::disk(config('vera.evidencia_manual_disk'));

        try {
            foreach ($rutas as $ruta) {
                $disco->delete($ruta);
            }
            // Todo lo especifico del tenant vive bajo este prefijo (seccion 3.1).
            $disco->deleteDirectory("tenants/{$tenantId}");
        } catch (Throwable $e) {
            Log::error('Baja de tenant: no se pudo borrar evidencia.', ['tenant_id' => $tenantId, 'error' => $e->getMessage()]);
        }
    }
}
