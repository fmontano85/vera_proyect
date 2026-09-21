<?php

declare(strict_types=1);

namespace App\Sources;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Google Custom Search JSON API (seccion 4/5.3 del manual). Requiere
 * GOOGLE_CSE_API_KEY + GOOGLE_CSE_CX en backend/.env (ver config/services.php).
 *
 * Cuota diaria (seccion 8/9 del CLAUDE.md raiz, GOOGLE_CSE_DAILY_LIMIT=100):
 * se hace cumplir AQUI, en el unico punto de la app que llama de verdad a
 * Google, no en el caller (consulta puntual hoy, el scheduler de
 * monitoreo continuo despues). Instruccion explicita del propietario: la
 * app nunca debe generar costo real por pasarse del nivel gratis, sin
 * importar que capa de arriba lo dispare ni cuantas veces se reintente.
 */
class GoogleCseAdapter implements SourceAdapterInterface
{
    private const URL = 'https://www.googleapis.com/customsearch/v1';

    public function buscar(string $query): array
    {
        $this->reservarCupoDiario();

        $response = Http::timeout(15)->retry(2, 500)->get(self::URL, [
            'key' => config('services.google_cse.api_key'),
            'cx' => config('services.google_cse.cx'),
            'q' => $query,
        ])->throw();

        $items = $response->json('items', []);

        return [
            'urls' => array_column($items, 'link'),
            // El JSON de Google CSE no trae un costo por request - el
            // costo real se lleva por conteo de consultas/dia contra
            // GOOGLE_CSE_DAILY_LIMIT, no por esta llamada individual.
            'costo' => null,
        ];
    }

    /**
     * Contador atomico por dia UTC en cache (funciona igual con
     * CACHE_STORE=array en tests y CACHE_STORE=redis en produccion).
     * Cache::add() crea la llave en 0 solo si no existe todavia hoy (sin
     * pisar un contador que otro worker ya esta incrementando - evita la
     * condicion de carrera de "poner TTL" contra "incrementar" entre
     * varios workers de Horizon corriendo en paralelo); Cache::increment()
     * es atomico sobre esa llave. Si ya se alcanzo el limite, se revierte
     * la reserva y se rechaza ANTES de llamar a Google - la unica forma
     * de garantizar que nunca se pasa del nivel gratis es no hacer la
     * llamada, no confiar en que Google la rechace.
     */
    private function reservarCupoDiario(): void
    {
        $llave = 'google_cse:consultas:'.now('UTC')->format('Y-m-d');
        $limite = (int) config('services.google_cse.daily_limit');

        Cache::add($llave, 0, now('UTC')->endOfDay()->addMinutes(5));
        $consumidas = Cache::increment($llave);

        if ($consumidas > $limite) {
            Cache::decrement($llave);

            throw new RuntimeException(
                "Cuota diaria de Google CSE ({$limite}) ya alcanzada hoy - la busqueda no se ejecuto para no generar costo fuera del nivel gratis."
            );
        }
    }
}
