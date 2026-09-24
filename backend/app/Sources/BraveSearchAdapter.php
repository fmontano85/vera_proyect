<?php

declare(strict_types=1);

namespace App\Sources;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Brave Search API (fuente activa por default desde 2026-09-24, ver
 * "Estatus de sesion" del CLAUDE.md raiz) - reemplaza a Google CSE:
 * Google Custom Search JSON API esta cerrada a clientes nuevos desde 2025
 * y Google la apaga por completo el 1 de enero de 2027, ademas del
 * bloqueo de facturacion que nunca se resolvio. Requiere
 * BRAVE_SEARCH_API_KEY en backend/.env (ver config/services.php).
 *
 * Cuota (seccion 8/9 del CLAUDE.md raiz, patron heredado de
 * GoogleCseAdapter): Brave factura MENSUAL, no diario ($5/1000 requests,
 * $5 de credito automatico cada mes = ~1000 gratis, SIN tope de gasto de
 * su lado desde feb-2026) - se hace cumplir AQUI, en el unico punto de la
 * app que llama de verdad a Brave, con el mismo candado atomico que
 * GoogleCseAdapter pero con ventana de mes en vez de dia.
 */
class BraveSearchAdapter implements SourceAdapterInterface
{
    private const URL = 'https://api.search.brave.com/res/v1/web/search';

    /**
     * Brave rechaza queries de mas de 600 caracteres / 75 palabras (limite
     * documentado de la API) - Google CSE no tenia este limite, asi que
     * RunSubjectSearchJob::construirQuery() no lo contempla. Se trunca
     * aqui, no en el job, para no acoplar el limite de un proveedor
     * especifico a la lógica generica de armar la query.
     */
    private const MAX_QUERY_LENGTH = 600;

    public function buscar(string $query): array
    {
        $this->reservarCupoMensual();

        $response = Http::timeout(15)
            ->retry(2, 500)
            ->withHeaders([
                'Accept' => 'application/json',
                'Accept-Encoding' => 'gzip',
                'X-Subscription-Token' => config('services.brave_search.api_key'),
            ])
            ->get(self::URL, [
                'q' => mb_substr($query, 0, self::MAX_QUERY_LENGTH),
            ])
            ->throw();

        $items = $response->json('web.results', []);

        return [
            // Seccion 3.7: metadata para listar el resultado SIN
            // descargarlo. 'description' de Brave trae HTML (<strong>
            // resaltando coincidencias) - se limpia antes de guardarlo.
            'resultados' => array_map(fn (array $item) => [
                'url' => $item['url'],
                'titulo' => $item['title'] ?? null,
                'descripcion' => isset($item['description']) ? strip_tags($item['description']) : null,
                'medio' => $item['meta_url']['hostname'] ?? null,
                'fecha' => $item['page_age'] ?? null,
            ], $items),
            // Brave si publica una tarifa fija ($5/1000), pero calcularla
            // bien depende de si el mes ya gasto la cuota gratis o no -
            // se deja null por ahora, igual que con Google CSE (seccion 3.4:
            // "no inventar costo").
            'costo' => null,
        ];
    }

    private function reservarCupoMensual(): void
    {
        $llave = 'brave_search:consultas:'.now('UTC')->format('Y-m');
        $limite = (int) config('services.brave_search.monthly_limit');

        Cache::add($llave, 0, now('UTC')->endOfMonth()->addMinutes(5));
        $consumidas = Cache::increment($llave);

        if ($consumidas > $limite) {
            Cache::decrement($llave);

            throw new RuntimeException(
                "Cuota mensual de Brave Search ({$limite}) ya alcanzada este mes - la busqueda no se ejecuto para no generar costo fuera de lo presupuestado."
            );
        }
    }
}
