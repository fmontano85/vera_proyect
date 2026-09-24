<?php

declare(strict_types=1);

namespace App\Services\Search;

use App\Enums\EstadoSearchResult;
use App\Enums\GapMotivo;
use App\Models\SearchResult;
use Carbon\CarbonImmutable;

/**
 * Bug real reportado por el usuario probando /subjects/5 (sesion
 * posterior a la 3.7, 2026-09-24): Brave devuelve noticias de hasta 2
 * anos atras y se listaban como 'nuevo' sin ningun filtro - el unico
 * chequeo de ventana vivia dentro de FetchArticleJob, que solo corre
 * DESPUES de que el analista pincha "Sacar informacion de noticia" para
 * ESE resultado en concreto. Un resultado viejo se quedaba en 'nuevo'
 * indefinidamente hasta que alguien lo descartara a mano uno por uno.
 *
 * Fix: Brave ya trae su propia fecha aproximada (fecha_brave, del campo
 * page_age) en el momento mismo de la busqueda, antes de cualquier fetch.
 * Se usa para marcar GAP/fuera_de_ventana YA en RunSubjectSearchJob y
 * RunTagSearchJob, al crear el search_result - no hace falta esperar a
 * que el analista pinche nada. Si fecha_brave viene null (Brave no la
 * trae para esa pagina), se deja en 'nuevo': mejor mostrar de mas que
 * descartar de menos por una fecha que no se pudo determinar - el chequeo
 * de FetchArticleJob con la fecha REAL del articulo (mas confiable, de
 * meta/JSON-LD) sigue siendo la ultima palabra si el analista igual pide
 * extraerlo.
 */
class VentanaTemporal
{
    /**
     * dias_atras (busqueda por tags) sobreescribe el default global
     * cuando el search_result trae su propia ventana configurada.
     */
    public static function diasPara(SearchResult $resultado): int
    {
        return $resultado->dias_atras ?? (int) config('vera.article_window_days');
    }

    public static function marcarSiFueraDeVentanaPorFechaBrave(SearchResult $resultado): void
    {
        if (! $resultado->wasRecentlyCreated || $resultado->fecha_brave === null) {
            return;
        }

        if ($resultado->fecha_brave->lt(CarbonImmutable::now()->subDays(self::diasPara($resultado)))) {
            $resultado->forceFill([
                'estado' => EstadoSearchResult::Gap,
                'gap_motivo' => GapMotivo::FueraDeVentana,
            ])->save();
        }
    }
}
