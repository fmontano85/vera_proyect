<?php

declare(strict_types=1);

namespace App\Support;

use Closure;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Entrega un ZIP temporal con datos personales (exportaciones de la
 * seccion 3.9) y garantiza dos cosas (hallazgos del code-review
 * 2026-09-28):
 *
 * - El archivo temporal se borra siempre, incluso si el cliente corta la
 *   descarga (register_shutdown_function corre aunque PHP aborte el
 *   script por conexion cerrada).
 * - $alEntregar (el registro en la bitacora) solo corre si la descarga se
 *   envio completa: la baja de un tenant depende de ese registro.
 */
final class DescargaZip
{
    public static function responder(string $ruta, string $nombre, Closure $alEntregar): StreamedResponse
    {
        register_shutdown_function(static fn () => is_file($ruta) && @unlink($ruta));

        return response()->streamDownload(function () use ($ruta, $alEntregar) {
            try {
                $enviados = readfile($ruta);
                if ($enviados !== false && ! connection_aborted()) {
                    $alEntregar();
                }
            } finally {
                @unlink($ruta);
            }
        }, $nombre, [
            'Content-Type' => 'application/zip',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
