<?php

declare(strict_types=1);

namespace App\Services\Search;

use App\Models\Source;
use Illuminate\Support\Collection;

/**
 * Restriccion de dominios por 'site:' para Brave (seccion 4 del CLAUDE.md
 * raiz) - extraido de RunSubjectSearchJob para reutilizarlo tambien en
 * RunTagSearchJob (busqueda por tags, sesion posterior a la 3.7). Brave no
 * tiene el equivalente al 'cx' de Google CSE: sin 'site:' en la query
 * busca en toda la web.
 */
class RestriccionDeDominios
{
    /**
     * Medios salvadorenses de la seccion 4. Fallback cuando la Source no
     * trae su propia lista en config['dominios'] (seccion 4: "ampliar tras
     * la prueba de cobertura" - eso se hace ahi, no aqui).
     */
    public const MEDIOS_DEFAULT = [
        'laprensagrafica.com',
        'elsalvador.com',
        'diarioelmundo.com',
        'lapagina.com.sv',
        'diario1.com',
        'elmundo.sv',
        'lanoticiasv.com',
    ];

    /**
     * @return Collection<int, string> cada elemento ya formateado "site:dominio"
     */
    public static function sitesPara(Source $source): Collection
    {
        return collect($source->config['dominios'] ?? null)
            ->whenEmpty(fn () => collect(self::MEDIOS_DEFAULT))
            ->map(fn (string $dominio) => "site:{$dominio}");
    }
}
