<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\SearchResult;
use App\Models\SearchRun;
use App\Models\Source;
use App\Models\Subject;
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

    /**
     * Medios salvadorenses de la seccion 4 del CLAUDE.md raiz. Con Google
     * CSE esta restriccion vivia del lado de Google (el 'cx' ya estaba
     * limitado a estos sitios) - la query nunca necesito filtrar por
     * dominio explicitamente. Brave Search API no tiene ese concepto: sin
     * 'site:' en la query busca en toda la web (asi se descubrio, en la
     * primera prueba real: "Juan Carlos Perez" devolvio un articulo de
     * Wikipedia sobre un beisbolista, no noticias salvadorenas). Se usa
     * como fallback cuando la Source no trae su propia lista en
     * config['dominios'] (seccion 4: "ampliar tras la prueba de
     * cobertura" - eso se hace ahi, no aqui).
     *
     * 'lanoticiasv.com' agregado 2026-09-24: un caso real (condena por
     * homicidio de una persona real buscada en la app) solo aparecia ahi,
     * no en ninguno de los 6 medios originales de la seccion 4 - el
     * usuario lo confirmo con una busqueda de Google y pidio agregarlo.
     */
    private const MEDIOS_DEFAULT = [
        'laprensagrafica.com',
        'elsalvador.com',
        'diarioelmundo.com',
        'lapagina.com.sv',
        'diario1.com',
        'elmundo.sv',
        'lanoticiasv.com',
    ];

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

        $query = $this->construirQuery($subject, $source);
        $resultado = $this->adapterFor($source)->buscar($query);

        $searchRun = SearchRun::create([
            'subject_id' => $subject->id,
            'source_id' => $source->id,
            'query' => $query,
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
        SearchResult::firstOrCreate(
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
    }

    private function construirQuery(Subject $subject, Source $source): string
    {
        $nombres = collect([$subject->nombre_canonico])
            ->merge($subject->aliases->pluck('nombre'))
            ->unique()
            ->map(fn (string $nombre) => "\"{$nombre}\"");

        $dominios = collect($source->config['dominios'] ?? null)
            ->whenEmpty(fn () => collect(self::MEDIOS_DEFAULT))
            ->map(fn (string $dominio) => "site:{$dominio}");

        return '('.$nombres->implode(' OR ').') ('.$dominios->implode(' OR ').')';
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
