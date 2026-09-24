<?php

declare(strict_types=1);

namespace App\Actions\SearchResults;

use App\Enums\EstadoSearchResult;
use App\Enums\OrigenMention;
use App\Models\Mention;
use App\Models\MentionMatch;
use App\Models\SearchResult;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Captura manual (seccion 3.7 del CLAUDE.md raiz) - solo valida en 'gap'
 * (el fetch automatico ya fallo, por eso hace falta capturar a mano).
 * Crea la mention YA con su match resuelto (no pasa por proponer ->
 * resolver, decision confirmada del usuario) - por eso la Policy solo
 * deja entrar a quien ya puede resolver en firme (seccion 3.2).
 *
 * Evidencia: PDF obligatorio (decision del usuario 2026-09-24), guardado
 * en el disco de config('vera.evidencia_manual_disk') - independiente de
 * FILESYSTEM_DISK a peticion explicita, configurable aparte en dev y
 * produccion.
 *
 * subject_id (busqueda por tags, sesion posterior a la 3.7): un resultado
 * que vino de una busqueda por tags no tiene subject propio - el
 * formulario debe traer a que subject del tenant se le atribuye el
 * hallazgo (CapturaManualRequest lo exige solo en ese caso).
 */
class CapturaManual
{
    /**
     * @param  array{nombre_como_aparece: string, rol: string, delitos: array<int, string>, fecha_hecho: ?string, resumen: ?string, estado_resolucion: string, subject_id: ?int}  $datos
     */
    public function handle(SearchResult $searchResult, User $user, array $datos, UploadedFile $pdf): MentionMatch
    {
        if ($searchResult->estado !== EstadoSearchResult::Gap) {
            throw new RuntimeException(
                "Solo se puede capturar manualmente un resultado en estado 'gap' (fetch fallido), no '{$searchResult->estado->value}'."
            );
        }

        $subjectId = $searchResult->subject_id ?? $datos['subject_id'] ?? null;
        if ($subjectId === null) {
            throw new RuntimeException(
                'Este resultado no tiene subject (vino de una busqueda por tags) - hace falta indicar a que persona vigilada se le atribuye.'
            );
        }

        return DB::transaction(function () use ($searchResult, $user, $datos, $pdf, $subjectId) {
            $path = "tenants/{$searchResult->tenant_id}/evidencia-manual/{$searchResult->id}.pdf";
            Storage::disk(config('vera.evidencia_manual_disk'))
                ->put($path, file_get_contents($pdf->getRealPath()));

            $mention = Mention::create([
                'search_result_id' => $searchResult->id,
                'nombre_extraido' => $datos['nombre_como_aparece'],
                'rol' => $datos['rol'],
                'delitos' => $datos['delitos'],
                'fecha_hecho' => $datos['fecha_hecho'] ?? null,
                'resumen' => $datos['resumen'] ?? null,
            ]);
            $mention->forceFill([
                'origen' => OrigenMention::Manual,
                'creado_por' => $user->id,
            ])->save();

            $match = MentionMatch::create([
                'mention_id' => $mention->id,
                'subject_id' => $subjectId,
            ]);
            $match->forceFill([
                'estado' => $datos['estado_resolucion'],
                'resuelto_por' => $user->id,
                'resuelto_en' => now(),
            ])->save();

            $searchResult->forceFill([
                'estado' => EstadoSearchResult::Extraido,
                'evidencia_manual_path' => $path,
            ])->save();

            return $match;
        });
    }
}
