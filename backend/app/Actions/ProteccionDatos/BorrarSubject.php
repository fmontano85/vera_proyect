<?php

declare(strict_types=1);

namespace App\Actions\ProteccionDatos;

use App\Models\Activity;
use App\Models\MentionMatch;
use App\Models\Report;
use App\Models\SanctionMatch;
use App\Models\SearchResult;
use App\Models\Subject;
use App\Models\SubjectAlias;
use App\Models\User;
use App\Services\ProteccionDatos\ResultadosDePersona;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Unico punto de borrado de una persona vigilada (seccion 3.9, puntos 3
 * y 5): lo usan el borrado por orden del cliente y la depuracion por
 * plazo de retencion. Requiere tenancy inicializada en el tenant de la
 * persona (el Subject llega ya resuelto por su scope).
 *
 * Se borra todo lo que es del tenant: persona, aliases, coincidencias,
 * hallazgos de sanciones, menciones de captura manual, resultados y
 * busquedas de la persona (la query lleva su nombre), alertas, evidencia
 * manual y su entrada en el indice de Meilisearch.
 *
 * Se conserva lo global: articulos y menciones automaticas (contenido
 * publico compartido entre tenants), que solo pierden el enlace al
 * resultado borrado.
 *
 * Bitacora: los registros de la persona se conservan (quien hizo que y
 * cuando) pero sin datos personales (cambios y propiedades se vacian), y
 * se agrega 'persona_eliminada' con el motivo. Irreversible.
 */
class BorrarSubject
{
    public const MOTIVO_ORDEN_DEL_CLIENTE = 'orden_del_cliente';

    public const MOTIVO_PLAZO_DE_RETENCION = 'plazo_de_retencion';

    /** @return array<string, int> conteo de lo borrado */
    public function handle(Subject $subject, string $motivo, ?User $por = null): array
    {
        $id = $subject->id;

        [$conteos, $rutasEvidencia] = DB::transaction(function () use ($subject, $id) {
            // Incluye resultados de busqueda por tags con captura manual atribuida a la persona.
            $resultadoIds = ResultadosDePersona::ids($id);
            $rutasEvidencia = DB::table('search_results')->whereIn('id', $resultadoIds)
                ->whereNotNull('evidencia_manual_path')->pluck('evidencia_manual_path')->all();

            // Una mencion manual se capturo para esta persona: por su
            // coincidencia o por colgar de uno de sus resultados.
            $mencionesManuales = DB::table('mentions')->where('origen', 'manual')
                ->where(fn ($q) => $q
                    ->whereIn('id', DB::table('matches')->where('subject_id', $id)->select('mention_id'))
                    ->orWhereIn('search_result_id', $resultadoIds))
                ->pluck('id');

            $matchIds = DB::table('matches')
                ->where(fn ($q) => $q->where('subject_id', $id)->orWhereIn('mention_id', $mencionesManuales))
                ->pluck('id');
            $sancionIds = DB::table('sanction_matches')->where('subject_id', $id)->pluck('id');
            $aliasIds = DB::table('subject_aliases')->where('subject_id', $id)->pluck('id');

            $this->anonimizarBitacora($id, $aliasIds->all(), $matchIds->all(), $sancionIds->all(), $resultadoIds->all());

            DB::table('matches')->whereIn('id', $matchIds)->delete();
            DB::table('sanction_matches')->whereIn('id', $sancionIds)->delete();
            DB::table('mentions')->whereIn('id', $mencionesManuales)->delete();
            DB::table('mentions')->whereIn('search_result_id', $resultadoIds)->update(['search_result_id' => null]);
            DB::table('search_results')->whereIn('id', $resultadoIds)->delete();
            $busquedas = DB::table('search_runs')->where('subject_id', $id)->delete();
            $alertas = DB::table('alerts')->where('alertable_type', Subject::class)->where('alertable_id', $id)->delete();

            // Reportes que incluyen a la persona (ficha, actividad, lista): se borran completos.
            $reportes = Report::query()->whereJsonContains('personas', $id)->get(['id', 'archivo_path']);
            $rutasEvidencia = [...$rutasEvidencia, ...$reportes->pluck('archivo_path')->filter()->all()];
            Report::query()->whereKey($reportes->modelKeys())->delete();
            DB::table('subject_aliases')->whereIn('id', $aliasIds)->delete();

            // Por el modelo (no DB::table) para que Scout lo saque del indice;
            // sin su propio registro 'deleted', que llevaria el nombre.
            $subject->disableLogging()->delete();

            return [[
                'aliases' => $aliasIds->count(),
                'coincidencias' => $matchIds->count(),
                'sanciones' => $sancionIds->count(),
                'menciones_manuales' => $mencionesManuales->count(),
                'resultados' => $resultadoIds->count(),
                'busquedas' => $busquedas,
                'alertas' => $alertas,
                'reportes' => $reportes->count(),
                'evidencias' => count($rutasEvidencia),
            ], $rutasEvidencia];
        });

        // Despues del commit: si la transaccion se revierte, la evidencia sigue.
        $this->borrarEvidencia($rutasEvidencia, $id);

        activity()->performedOn($subject)->causedBy($por)->event('persona_eliminada')
            ->withProperties(['motivo' => $motivo, 'eliminado' => $conteos])
            ->log('Persona y sus datos eliminados');

        return $conteos;
    }

    /**
     * @param  list<int>  $aliasIds
     * @param  list<int>  $matchIds
     * @param  list<int>  $sancionIds
     * @param  list<int>  $resultadoIds
     */
    private function anonimizarBitacora(int $subjectId, array $aliasIds, array $matchIds, array $sancionIds, array $resultadoIds): void
    {
        Activity::query()
            ->where(function (Builder $q) use ($subjectId, $aliasIds, $matchIds, $sancionIds, $resultadoIds) {
                $q->where(fn (Builder $s) => $s->where('subject_type', Subject::class)->where('subject_id', $subjectId))
                    ->orWhere(fn (Builder $s) => $s->where('subject_type', MentionMatch::class)->whereIn('subject_id', $matchIds))
                    ->orWhere(fn (Builder $s) => $s->where('subject_type', SanctionMatch::class)->whereIn('subject_id', $sancionIds))
                    ->orWhere(fn (Builder $s) => $s->where('subject_type', SearchResult::class)->whereIn('subject_id', $resultadoIds))
                    ->orWhere(fn (Builder $s) => $s->where('subject_type', SubjectAlias::class)
                        ->where(fn (Builder $j) => $j
                            ->whereIn('subject_id', $aliasIds)
                            // Aliases ya borrados antes: se reconocen por el subject_id guardado.
                            ->orWhere('attribute_changes->attributes->subject_id', $subjectId)
                            ->orWhere('attribute_changes->old->subject_id', $subjectId)));
            })
            ->update([
                'attribute_changes' => null,
                'properties' => json_encode(['datos_personales_eliminados' => true]),
            ]);
    }

    /** @param  list<string>  $rutas */
    private function borrarEvidencia(array $rutas, int $subjectId): void
    {
        $disco = Storage::disk(config('vera.evidencia_manual_disk'));

        foreach ($rutas as $ruta) {
            try {
                $disco->delete($ruta);
            } catch (Throwable $e) {
                // Los datos ya se borraron; un archivo huerfano no debe
                // revertirlos. Queda en el log para limpiarlo a mano.
                Log::error('No se pudo borrar evidencia manual de una persona eliminada.', [
                    'subject_id' => $subjectId, 'ruta' => $ruta, 'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
