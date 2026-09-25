<?php

declare(strict_types=1);

namespace App\Services\Search;

use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Limite real de la query de Brave (referencia oficial, verificado el
 * 2026-09-25, undecimo bloque del CLAUDE.md raiz): 600 caracteres Y 75
 * palabras. Antes el adaptador truncaba con mb_substr a 600 caracteres -
 * eso puede cortar a la mitad un parentesis o un 'site:' y dejar una
 * query mal formada, y no controlaba las palabras.
 *
 * Regla confirmada por el usuario: si no cabe, se omiten terminos desde
 * el FINAL (aliases en consulta puntual, tags en busqueda por tags). El
 * primer termino (nombre canonico / primer tag) y todos los 'site:' no se
 * quitan nunca - si ni con eso cabe, se lanza excepcion.
 */
class LimiteDeQuery
{
    public const MAX_CARACTERES = 600;

    public const MAX_PALABRAS = 75;

    /**
     * @param  Collection<int, string>  $terminos  sin comillas; el primero es obligatorio
     * @param  Collection<int, string>  $sites  ya formateados "site:dominio"
     * @return array{query: string, omitidos: list<string>}
     */
    public static function construir(Collection $terminos, Collection $sites): array
    {
        $incluidos = $terminos->values();
        $omitidos = [];

        while (true) {
            $query = self::armar($incluidos, $sites);

            if (! self::excede($query)) {
                return ['query' => $query, 'omitidos' => array_reverse($omitidos)];
            }

            if ($incluidos->count() <= 1) {
                throw new InvalidArgumentException(
                    'La query no cabe en el limite de Brave ('.self::MAX_CARACTERES.' caracteres / '.self::MAX_PALABRAS.' palabras) ni siquiera con un solo termino.'
                );
            }

            $omitidos[] = $incluidos->pop();
        }
    }

    public static function excede(string $query): bool
    {
        return mb_strlen($query) > self::MAX_CARACTERES
            || self::contarPalabras($query) > self::MAX_PALABRAS;
    }

    public static function contarPalabras(string $query): int
    {
        return count(preg_split('/\s+/u', trim($query), -1, PREG_SPLIT_NO_EMPTY));
    }

    /**
     * @param  Collection<int, string>  $terminos
     * @param  Collection<int, string>  $sites
     */
    private static function armar(Collection $terminos, Collection $sites): string
    {
        $entreComillas = $terminos->map(fn (string $termino) => "\"{$termino}\"");

        return '('.$entreComillas->implode(' OR ').') ('.$sites->implode(' OR ').')';
    }
}
