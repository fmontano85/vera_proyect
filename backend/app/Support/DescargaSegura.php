<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Contracts\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Descarga un archivo SIEMPRE como adjunto con nosniff. Punto unico para
 * cualquier endpoint que sirva un archivo guardado (evidencia, reportes,
 * etc.) - antes de esto, la mitigacion completa (attachment + nosniff, y
 * para contenido de terceros ademas forzar un Content-Type inerte) vivia
 * copiada a mano dentro de SearchResultController::evidencia(); un futuro
 * endpoint (ej. servir un RSS/sitemap raspado, seccion 4/9 del CLAUDE.md
 * raiz) tiene ahora donde reutilizarla en vez de tener que recordar las
 * 3 partes por su cuenta.
 */
class DescargaSegura
{
    /**
     * Para contenido de TERCEROS (ej. un snapshot HTML raspado de un
     * medio): el Content-Type real nunca se envia, siempre uno inerte -
     * el navegador no debe poder ejecutarlo ni interpretarlo como HTML
     * aunque el nombre sugiera lo contrario (XSS almacenado, OWASP A03).
     */
    public static function deTercero(Filesystem $disco, string $path, string $nombre): StreamedResponse
    {
        return self::descargar($disco, $path, $nombre, 'text/plain; charset=UTF-8');
    }

    /** Para un archivo propio (ej. el PDF de captura manual) - Content-Type real, igual con nosniff. */
    public static function propia(Filesystem $disco, string $path, string $nombre, string $mime): StreamedResponse
    {
        return self::descargar($disco, $path, $nombre, $mime);
    }

    private static function descargar(Filesystem $disco, string $path, string $nombre, string $mime): StreamedResponse
    {
        return $disco->download($path, $nombre, [
            'Content-Type' => $mime,
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
