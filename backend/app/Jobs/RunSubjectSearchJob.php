<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\SearchRun;
use App\Models\Source;
use App\Models\Subject;
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
 * search_run y encola FetchArticleJob por cada URL nueva.
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

        $query = $this->construirQuery($subject);
        $resultado = $this->adapterFor($source)->buscar($query);

        $searchRun = SearchRun::create([
            'subject_id' => $subject->id,
            'source_id' => $source->id,
            'query' => $query,
            'resultados' => $resultado['urls'],
            'costo' => $resultado['costo'],
        ]);

        foreach ($resultado['urls'] as $url) {
            FetchArticleJob::dispatch($url);
        }

        return $searchRun;
    }

    private function construirQuery(Subject $subject): string
    {
        $nombres = collect([$subject->nombre_canonico])
            ->merge($subject->aliases->pluck('nombre'))
            ->unique()
            ->map(fn (string $nombre) => "\"{$nombre}\"");

        return $nombres->implode(' OR ');
    }

    private function adapterFor(Source $source): SourceAdapterInterface
    {
        return match ($source->tipo) {
            'cse' => app(GoogleCseAdapter::class),
            default => throw new InvalidArgumentException(
                "Adaptador no implementado todavia para fuentes de tipo '{$source->tipo}'."
            ),
        };
    }
}
