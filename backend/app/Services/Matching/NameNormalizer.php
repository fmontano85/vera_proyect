<?php

declare(strict_types=1);

namespace App\Services\Matching;

/**
 * Normalizacion basica para matching de nombres (seccion 3.4 del CLAUDE.md
 * raiz: "unaccent, minusculas, orden de tokens"). Meilisearch ya aplica
 * tolerancia a errores (typos) por su cuenta - esto es solo para que dos
 * formas equivalentes del mismo nombre ("Perez Juan" / "Juan Perez",
 * "Pérez" / "Perez") produzcan la misma consulta.
 */
class NameNormalizer
{
    public static function normalize(string $name): string
    {
        $sinAcentos = iconv('UTF-8', 'ASCII//TRANSLIT', $name) ?: $name;
        $minusculas = mb_strtolower($sinAcentos);

        $tokens = preg_split('/\s+/', trim($minusculas), -1, PREG_SPLIT_NO_EMPTY);
        sort($tokens);

        return implode(' ', $tokens);
    }
}
