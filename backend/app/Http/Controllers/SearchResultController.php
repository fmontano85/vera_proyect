<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\SearchResults\CapturaManual;
use App\Actions\SearchResults\DescartarResultado;
use App\Actions\SearchResults\ExtraerResultado;
use App\Http\Requests\CapturaManualRequest;
use App\Models\SearchResult;
use App\Models\Subject;
use App\Services\Evidence\DocumentoEvidencia;
use App\Services\Evidence\GeneradorPdf;
use App\Support\DescargaSegura;
use App\Support\RegistroDeAccesos;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Flujo bajo demanda (seccion 3.7 del CLAUDE.md raiz). Delgado a
 * proposito - la logica vive en app/Actions/SearchResults.
 */
class SearchResultController extends Controller
{
    public function index(Subject $subject): JsonResponse
    {
        $this->authorize('view', $subject);

        /**
         * Seccion 3.8: resultados que aparecieron despues del ultimo
         * seguimiento realizado. firstOrCreate por (subject_id, url_hash)
         * en RunSubjectSearchJob garantiza que created_at es la primera
         * vez que esa URL salio para el subject - sin columna extra. Si
         * nunca hubo seguimiento, nada se marca.
         */
        $ultimoSeguimiento = $subject->ultimo_seguimiento_en;

        $resultados = SearchResult::query()
            ->where('subject_id', $subject->id)
            ->with(['mentions' => fn ($q) => $q->with('match'), 'article'])
            ->latest()
            ->paginate()
            ->through(fn (SearchResult $resultado) => $resultado->toArray() + [
                'nuevo_desde_ultimo_seguimiento' => $ultimoSeguimiento !== null && $resultado->created_at->gt($ultimoSeguimiento),
            ]);

        return response()->json($resultados);
    }

    public function extraer(SearchResult $resultado, ExtraerResultado $action): JsonResponse
    {
        $this->authorize('extraer', $resultado);

        try {
            $resultado = $action->handle($resultado);
        } catch (RuntimeException $e) {
            return response()->json(['mensaje' => $e->getMessage()], 422);
        }

        RegistroDeAccesos::registrar('extraccion_solicitada', 'Extracción de noticia solicitada', $resultado);

        return response()->json($resultado);
    }

    public function descartar(SearchResult $resultado, DescartarResultado $action): JsonResponse
    {
        $this->authorize('descartar', $resultado);

        try {
            $resultado = $action->handle($resultado, request()->user());
        } catch (RuntimeException $e) {
            return response()->json(['mensaje' => $e->getMessage()], 422);
        }

        RegistroDeAccesos::registrar('resultado_descartado', 'Resultado descartado', $resultado);

        return response()->json($resultado);
    }

    public function capturaManual(
        CapturaManualRequest $request,
        SearchResult $resultado,
        CapturaManual $action,
    ): JsonResponse {
        $this->authorize('capturaManual', $resultado);

        try {
            $match = $action->handle(
                $resultado,
                $request->user(),
                $request->safe()->except('pdf'),
                $request->file('pdf'),
            );
        } catch (RuntimeException $e) {
            return response()->json(['mensaje' => $e->getMessage()], 422);
        }

        return response()->json($match, 201);
    }

    /**
     * Descarga de evidencia (seccion 3.6). El aislamiento de tenant lo da
     * el route-model-binding (SearchResult tiene scope). La mitigacion de
     * XSS (attachment + nosniff, Content-Type inerte para el snapshot)
     * vive en App\Support\DescargaSegura, compartida con cualquier futuro
     * endpoint que sirva contenido guardado.
     */
    public function evidencia(SearchResult $resultado, string $tipo, DocumentoEvidencia $documento, GeneradorPdf $pdf): SymfonyResponse
    {
        $this->authorize('view', $resultado);

        // rutaOFallar() aborta con 404 antes de llegar al registro: solo se
        // registra una descarga que de verdad se sirve.
        $respuesta = match ($tipo) {
            // Seccion 3.6: PDF bajo demanda desde la evidencia guardada, nunca desde la URL viva.
            'pdf' => response($pdf->desdeVista('pdf.evidencia', $documento->datos($resultado) ?? abort(404, 'Esa evidencia no está disponible.')), 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => "attachment; filename=\"evidencia-{$resultado->id}.pdf\"",
                'X-Content-Type-Options' => 'nosniff',
            ]),
            'snapshot' => DescargaSegura::deTercero(
                Storage::disk(),
                $this->rutaOFallar($resultado->article?->evidence_path, Storage::disk()),
                "evidencia-{$resultado->id}-snapshot.html.txt",
            ),
            'manual' => DescargaSegura::propia(
                Storage::disk(config('vera.evidencia_manual_disk')),
                $this->rutaOFallar($resultado->evidencia_manual_path, Storage::disk(config('vera.evidencia_manual_disk'))),
                "evidencia-{$resultado->id}.pdf",
                'application/pdf',
            ),
            default => abort(404, 'Tipo de evidencia desconocido.'),
        };

        RegistroDeAccesos::registrar('evidencia_descargada', 'Evidencia descargada', $resultado, ['tipo' => $tipo]);

        return $respuesta;
    }

    private function rutaOFallar(?string $path, Filesystem $disco): string
    {
        abort_if($path === null || ! $disco->exists($path), 404, 'Esa evidencia no está disponible.');

        return $path;
    }
}
