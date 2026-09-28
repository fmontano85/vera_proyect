<?php

declare(strict_types=1);

namespace App\Services\Evidence;

use Illuminate\Support\Facades\Log;

/**
 * Texto legible de un snapshot HTML de terceros, por parrafos (seccion
 * 3.6: el PDF se arma con la evidencia guardada). Nunca se renderiza el
 * HTML original: se descartan scripts, estilos, iframes, imagenes y todo
 * recurso externo, y el texto resultante se imprime escapado en la vista.
 *
 * - Codificacion: FetchArticleJob guarda el cuerpo tal cual; una pagina en
 *   ISO-8859-1/Windows-1252 se convierte a UTF-8 antes de procesarla (si
 *   no, cada linea con tildes o enes se perdia en silencio).
 * - Contenido principal: el <article> con mas texto (las notas
 *   relacionadas tambien suelen ser <article> y traen nombres de otras
 *   personas), si no <main>, si no la pagina entera sin menus, cabecera,
 *   pie ni barras laterales. Dentro del articulo se conserva su <header>
 *   (titular y firma).
 * - Tope de MAX_PARRAFOS: el PDF se genera en la peticion HTTP; el snapshot
 *   completo sigue disponible como descarga aparte.
 * - Un fallo de PCRE (limite de backtracking en paginas enormes) no deja
 *   el documento vacio en silencio: se registra y se usa strip_tags.
 *
 * (Hallazgos del code-review 2026-09-28.)
 */
final class TextoDeEvidencia
{
    public const MAX_PARRAFOS = 400;

    private const SIEMPRE_DESCARTADOS = 'script|style|noscript|template|svg|iframe|object|canvas|form|nav|aside|footer';

    private const FIN_DE_BLOQUE = 'p|div|h[1-6]|li|tr|article|section|blockquote|title|pre|figcaption|dd|dt|header';

    /** @return list<string> */
    public static function parrafos(string $html): array
    {
        return self::extraer($html)['parrafos'];
    }

    /** @return array{parrafos: list<string>, recortado: bool} */
    public static function extraer(string $html): array
    {
        $html = self::aUtf8($html);
        [$contenido, $esPrincipal] = self::contenidoPrincipal($html);

        // Fuera del articulo principal, <header> es la cabecera del sitio.
        $descartados = $esPrincipal ? self::SIEMPRE_DESCARTADOS : self::SIEMPRE_DESCARTADOS.'|header';
        $sinRuido = self::reemplazar('#<('.$descartados.')\b[^>]*>.*?</\1\s*>#is', ' ', $contenido)
            ?? strip_tags($contenido);
        $conSaltos = self::reemplazar(['#<br\s*/?>#i', '#</('.self::FIN_DE_BLOQUE.')\s*>#i'], "\n", $sinRuido) ?? $sinRuido;
        $texto = html_entity_decode(strip_tags($conSaltos), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $lineas = array_map(
            fn (string $linea) => trim(self::reemplazar('/[ \t\x{00A0}]+/u', ' ', $linea) ?? $linea),
            explode("\n", $texto),
        );
        $parrafos = array_values(array_filter($lineas, fn (string $linea) => $linea !== ''));

        return [
            'parrafos' => array_slice($parrafos, 0, self::MAX_PARRAFOS),
            'recortado' => count($parrafos) > self::MAX_PARRAFOS,
        ];
    }

    private static function aUtf8(string $html): string
    {
        if (mb_check_encoding($html, 'UTF-8')) {
            return $html;
        }

        $declarado = preg_match('/charset=["\']?([\w-]+)/i', $html, $m) === 1 ? strtoupper($m[1]) : null;
        $origen = $declarado !== null && in_array($declarado, array_map('strtoupper', mb_list_encodings()), true) && $declarado !== 'UTF-8'
            ? $declarado
            : 'Windows-1252';

        return mb_convert_encoding($html, 'UTF-8', $origen);
    }

    /** @return array{0: string, 1: bool} contenido y si es el contenido principal marcado por la pagina */
    private static function contenidoPrincipal(string $html): array
    {
        if (preg_match_all('#<article\b[^>]*>(.*?)</article\s*>#is', $html, $articulos) > 0) {
            $mayor = collect($articulos[1])->sortByDesc(fn (string $a) => mb_strlen(trim(strip_tags($a))))->first();
            if (trim(strip_tags((string) $mayor)) !== '') {
                return [(string) $mayor, true];
            }
        }

        if (preg_match('#<main\b[^>]*>(.*)</main\s*>#is', $html, $main) === 1 && trim(strip_tags($main[1])) !== '') {
            return [$main[1], true];
        }

        return [$html, false];
    }

    /** @param  string|list<string>  $patron */
    private static function reemplazar(string|array $patron, string $por, string $texto): ?string
    {
        $resultado = preg_replace($patron, $por, $texto);

        if ($resultado === null) {
            Log::warning('TextoDeEvidencia: fallo de expresion regular al limpiar un snapshot.', [
                'error' => preg_last_error_msg(),
                'bytes' => strlen($texto),
            ]);
        }

        return $resultado;
    }
}
