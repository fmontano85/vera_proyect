<?php

declare(strict_types=1);

namespace App\Services\Evidence;

/**
 * Texto legible de un snapshot HTML de terceros, por parrafos (seccion
 * 3.6: el PDF se arma con la evidencia guardada). Nunca se renderiza el
 * HTML original: se descartan scripts, estilos, iframes, imagenes y todo
 * recurso externo, y el texto resultante se imprime escapado en la vista.
 *
 * Si la pagina marca su contenido principal (<article>, si no <main>) se
 * usa solo ese; siempre se descartan menus, cabecera, pie y barras
 * laterales del sitio. El snapshot completo sigue disponible como
 * descarga aparte (evidencia 'snapshot').
 */
final class TextoDeEvidencia
{
    private const BLOQUES_DESCARTADOS = 'script|style|noscript|template|svg|iframe|object|canvas|nav|header|footer|aside|form';

    private const FIN_DE_BLOQUE = 'p|div|h[1-6]|li|tr|article|section|blockquote|title|pre|figcaption|dd|dt';

    /** @return list<string> */
    public static function parrafos(string $html): array
    {
        $principal = self::contenidoPrincipal($html);
        $sinRuido = (string) preg_replace('#<('.self::BLOQUES_DESCARTADOS.')\b[^>]*>.*?</\1\s*>#is', ' ', $principal);
        $conSaltos = (string) preg_replace(['#<br\s*/?>#i', '#</('.self::FIN_DE_BLOQUE.')\s*>#i'], "\n", $sinRuido);
        $texto = html_entity_decode(strip_tags($conSaltos), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $lineas = array_map(
            fn (string $linea) => trim((string) preg_replace('/[ \t\x{00A0}]+/u', ' ', $linea)),
            explode("\n", $texto),
        );

        return array_values(array_filter($lineas, fn (string $linea) => $linea !== ''));
    }

    private static function contenidoPrincipal(string $html): string
    {
        foreach (['article', 'main'] as $etiqueta) {
            if (preg_match('#<'.$etiqueta.'\b[^>]*>(.*)</'.$etiqueta.'\s*>#is', $html, $coincidencia) === 1
                && trim(strip_tags($coincidencia[1])) !== '') {
                return $coincidencia[1];
            }
        }

        return $html;
    }
}
