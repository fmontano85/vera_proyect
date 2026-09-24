<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\SearchResult;
use App\Models\SearchRun;
use App\Models\Source;
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
 * Busqueda por tags (sesion posterior a la 3.7, 2026-09-24): en vez de
 * nombre+aliases de un subject, construye la query con los tags elegidos
 * por el analista (delitos: hurto, estafa, etc.). subject_id queda null
 * en el search_run/search_results resultantes - el matching contra la
 * lista de vigilancia lo hace igual MatchMentionsJob (ya es agnostico de
 * subject, corre por tenant completo). Mismo patron de idempotencia y
 * dominios que RunSubjectSearchJob.
 */
class RunTagSearchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @param  list<string>  $tags  nombres de tags ya validados contra el catalogo del tenant (App\Actions\TagSearches\IniciarBusquedaPorTags)
     */
    public function __construct(
        private readonly array $tags,
        private readonly int $sourceId,
        private readonly ?int $diasAtras = null,
    ) {
        $this->onQueue('search');
    }

    public function handle(): SearchRun
    {
        $source = Source::findOrFail($this->sourceId);
        $tagsOrdenados = collect($this->tags)->sort()->values()->all();

        /**
         * Idempotencia (seccion 7): mismo criterio que RunSubjectSearchJob,
         * pero la clave es la combinacion exacta de tags (no un subject) -
         * BelongsToTenant ya filtra por el tenant ambiente.
         */
        $existente = SearchRun::whereNull('subject_id')
            ->where('source_id', $source->id)
            ->where('tags', json_encode($tagsOrdenados))
            ->whereDate('created_at', today())
            ->first();

        if ($existente !== null) {
            return $existente;
        }

        $query = $this->construirQuery($tagsOrdenados, $source);
        $resultado = $this->adapterFor($source)->buscar($query);

        $searchRun = SearchRun::create([
            'source_id' => $source->id,
            'query' => $query,
            'tags' => $tagsOrdenados,
            'dias_atras' => $this->diasAtras,
            'resultados' => $resultado['resultados'],
            'costo' => $resultado['costo'],
        ]);

        foreach ($resultado['resultados'] as $item) {
            $this->guardarSearchResult($searchRun, $item);
        }

        return $searchRun;
    }

    /**
     * firstOrCreate por (subject_id=null, url_hash): BelongsToTenant ya
     * scopea la busqueda/creacion al tenant ambiente (ver migracion
     * add_busqueda_por_tags_a_search_runs_y_results: unique
     * (tenant_id, subject_id, url_hash)), asi que dos busquedas por tags
     * del mismo tenant no duplican la misma URL.
     */
    private function guardarSearchResult(SearchRun $searchRun, array $item): void
    {
        $resultado = SearchResult::firstOrCreate(
            [
                'subject_id' => null,
                'url_hash' => hash('sha256', $item['url']),
            ],
            [
                'search_run_id' => $searchRun->id,
                'url' => $item['url'],
                'titulo' => $item['titulo'],
                'snippet' => $item['descripcion'],
                'medio' => $item['medio'],
                'fecha_brave' => $item['fecha'],
                'dias_atras' => $this->diasAtras,
            ],
        );

        VentanaTemporal::marcarSiFueraDeVentanaPorFechaBrave($resultado);
    }

    /**
     * @param  list<string>  $tags
     */
    private function construirQuery(array $tags, Source $source): string
    {
        $tagsQuery = collect($tags)->map(fn (string $tag) => "\"{$tag}\"");
        $dominios = RestriccionDeDominios::sitesPara($source);

        return '('.$tagsQuery->implode(' OR ').') ('.$dominios->implode(' OR ').')';
    }

    private function adapterFor(Source $source): SourceAdapterInterface
    {
        return match ($source->tipo) {
            'brave' => app(BraveSearchAdapter::class),
            'cse' => app(GoogleCseAdapter::class),
            default => throw new InvalidArgumentException(
                "Adaptador no implementado todavia para fuentes de tipo '{$source->tipo}'."
            ),
        };
    }
}
