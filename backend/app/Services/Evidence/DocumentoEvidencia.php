<?php

declare(strict_types=1);

namespace App\Services\Evidence;

use App\Models\SearchResult;
use Illuminate\Support\Facades\Storage;

/**
 * Datos del PDF de evidencia de un resultado (seccion 3.6): portada con
 * URL, fecha de captura y hash, verificacion de integridad del snapshot
 * guardado contra el hash registrado al capturarlo, y el texto del
 * articulo. Devuelve null si no hay snapshot.
 */
class DocumentoEvidencia
{
    /** @return array<string, mixed>|null */
    public function datos(SearchResult $resultado): ?array
    {
        $articulo = $resultado->article;

        if ($articulo?->evidence_path === null || ! Storage::exists($articulo->evidence_path)) {
            return null;
        }

        $html = (string) Storage::get($articulo->evidence_path);
        $hashActual = hash('sha256', $html);

        return [
            'resultado_id' => $resultado->id,
            'url' => $articulo->url,
            'titulo' => $articulo->titulo ?? $resultado->titulo,
            'medio' => $articulo->medio,
            'fecha_publicacion' => $articulo->fecha_publicacion?->timezone(config('vera.zona_horaria'))->format('d/m/Y H:i'),
            'capturado_en' => $articulo->created_at?->utc()->format('d/m/Y H:i:s').' UTC',
            'hash' => $articulo->hash_contenido,
            'hash_actual' => $hashActual,
            'integridad_verificada' => $articulo->hash_contenido !== null && hash_equals($articulo->hash_contenido, $hashActual),
            'parrafos' => TextoDeEvidencia::parrafos($html),
            'generado_en' => now()->timezone(config('vera.zona_horaria'))->format('d/m/Y H:i'),
        ];
    }
}
