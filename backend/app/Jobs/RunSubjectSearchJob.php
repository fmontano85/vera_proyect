<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\SearchResult;
use App\Models\SearchRun;
use App\Models\Source;
use App\Models\Subject;
use App\Services\Search\LimiteDeQuery;
use App\Services\Search\RestriccionDeDominios;
use App\Services\Search\VentanaTemporal;
use App\Sources\BraveSearchAdapter;
use App\Sources\GoogleCseAdapter;
use App\Sources\SourceAdapterInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use InvalidArgumentException;

/**
 * Por subject y source (seccion 3.4): construye la query con el nombre
 * canonico + aliases, llama al adaptador de la fuente, persiste el
 * search_run y un search_result por cada resultado (seccion 3.7, flujo
 * bajo demanda 2026-09-24). YA NO encola FetchArticleJob directo - eso
 * solo pasa cuando el analista lo pide (POST /resultados/{id}/extraer,
 * App\Actions\SearchResults\ExtraerResultado).
 */
class RunSubjectSearchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        private readonly int $subjectId,
        private readonly int $sourceId,
    ) {
        $this->onQueue('search');
    }

    public function handle(): SearchRun
    {
        $subject = Subject::with('aliases')->findOrFail($this->subjectId);
        $source = Source::findOrFail($this->sourceId);

        /**
         * Idempotencia (seccion 7: "cada job debe ser idempotente y
         * reintentable"): si Horizon reintenta este job despues de que ya
         * corrio hoy para este subject+source (ej. el worker murio entre
         * el create() y el ultimo dispatch de FetchArticleJob), no se
         * vuelve a gastar cuota de Google CSE ni a duplicar el search_run
         * - se reusa el que ya existe.
         */
        $existente = SearchRun::where('subject_id', $subject->id)
            ->where('source_id', $source->id)
            ->whereDate('created_at', today())
            ->first();

        if ($existente !== null) {
            return $existente;
        }

        ['query' => $query, 'omitidos' => $omitidos] = $this->construirQuery($subject, $source);

        // Filtro temporal en origen (decimo bloque): la consulta puntual
        // usa ARTICLE_WINDOW_DAYS.
        $resultado = $this->adapterFor($source)->buscar($query, (int) config('vera.article_window_days'));

        $searchRun = SearchRun::create([
            'subject_id' => $subject->id,
            'source_id' => $source->id,
            'query' => $query,
            'metadata_query' => [
                'proveedor' => $resultado['metadata'],
                'terminos_omitidos' => $omitidos,
            ],
            'resultados' => $resultado['resultados'],
            'costo' => $resultado['costo'],
        ]);

        foreach ($resultado['resultados'] as $item) {
            $this->guardarSearchResult($subject, $searchRun, $item);
        }

        return $searchRun;
    }

    /**
     * firstOrCreate por (subject_id, url_hash): si la misma URL ya salio
     * en una busqueda anterior para este subject (otro dia), no se
     * duplica la tarjeta ni se le resetea el estado si el analista ya la
     * proceso - solo se crea si es realmente nueva para este subject.
     */
    private function guardarSearchResult(Subject $subject, SearchRun $searchRun, array $item): void
    {
        $resultado = SearchResult::firstOrCreate(
            [
                'subject_id' => $subject->id,
                'url_hash' => hash('sha256', $item['url']),
            ],
            [
                'search_run_id' => $searchRun->id,
                'url' => $item['url'],
                'titulo' => $item['titulo'],
                'snippet' => $item['descripcion'],
                'medio' => $item['medio'],
                'fecha_brave' => $item['fecha'],
            ],
        );

        VentanaTemporal::marcarSiFueraDeVentanaPorFechaBrave($resultado);
    }

    /**
     * Nombre canonico primero: si la query no cabe en el limite de Brave,
     * LimiteDeQuery omite aliases desde el final, nunca el canonico ni
     * los 'site:'. Aliases ordenados por id (orden de alta) para que el
     * recorte sea determinista: se omiten primero los mas recientes.
     *
     * @return array{query: string, omitidos: list<string>}
     */
    private function construirQuery(Subject $subject, Source $source): array
    {
        $nombres = collect([$subject->nombre_canonico])
            ->merge($subject->aliases->sortBy('id')->pluck('nombre'))
            ->unique()
            ->values();

        return LimiteDeQuery::construir($nombres, RestriccionDeDominios::sitesPara($source));
    }

    private function adapterFor(Source $source): SourceAdapterInterface
    {
        return match ($source->tipo) {
            'brave' => app(BraveSearchAdapter::class),
            // Google CSE cerrada a clientes nuevos desde 2025 y se apaga
            // por completo el 1 de enero de 2027 (ademas del bloqueo de
            // facturacion sin resolver) - el codigo se queda por si se
            // retoma antes de esa fecha, pero 'brave' es la fuente activa.
            'cse' => app(GoogleCseAdapter::class),
            default => throw new InvalidArgumentException(
                "Adaptador no implementado todavia para fuentes de tipo '{$source->tipo}'."
            ),
        };
    }
}
