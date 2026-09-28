<?php

declare(strict_types=1);

namespace App\Services\Evidence;

use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * Genera PDF desde vistas Blade con dompdf (decision del usuario
 * 2026-09-28: libreria PHP, sin contenedor nuevo ni servicio externo).
 * Configuracion cerrada a proposito: sin recursos remotos, sin
 * JavaScript ni PHP embebido - el contenido puede venir de terceros.
 */
class GeneradorPdf
{
    /** @param  array<string, mixed>  $datos */
    public function desdeVista(string $vista, array $datos, string $orientacion = 'portrait'): string
    {
        $directorio = storage_path('app/dompdf');
        if (! is_dir($directorio)) {
            mkdir($directorio, 0775, true);
        }

        $opciones = new Options;
        $opciones->setIsRemoteEnabled(false);
        $opciones->setIsJavascriptEnabled(false);
        $opciones->setIsPhpEnabled(false);
        $opciones->setDefaultFont('DejaVu Sans');
        $opciones->setFontDir($directorio);
        $opciones->setFontCache($directorio);
        $opciones->setTempDir($directorio);
        $opciones->setChroot(resource_path('views/pdf'));

        $dompdf = new Dompdf($opciones);
        $dompdf->loadHtml(view($vista, $datos)->render(), 'UTF-8');
        $dompdf->setPaper('letter', $orientacion);
        $dompdf->render();

        // Numero de pagina en el pie (sin PHP embebido en la vista).
        $canvas = $dompdf->getCanvas();
        $fuente = $dompdf->getFontMetrics()->getFont('DejaVu Sans');
        $canvas->page_text($canvas->get_width() - 110, $canvas->get_height() - 28, 'Página {PAGE_NUM} de {PAGE_COUNT}', $fuente, 7, [0.45, 0.45, 0.45]);

        return (string) $dompdf->output();
    }
}
