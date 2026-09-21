<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Article;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\DomCrawler\Crawler;

/**
 * articles es global (sin tenant_id, ver seccion 3.1 del CLAUDE.md raiz).
 * Alcance de esta primera version (Fase 1, consulta puntual): un articulo
 * se descarga UNA vez por url; no hay re-captura/versionado todavia (eso
 * es Fase 2, monitoreo continuo) - si la url ya existe, no hace nada.
 */
class FetchArticleJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(private readonly string $url)
    {
        $this->onQueue('fetch');
    }

    public function handle(): ?Article
    {
        $urlHash = hash('sha256', $this->url);

        if (Article::where('url_hash', $urlHash)->exists()) {
            return null;
        }

        // timeout/retry moderados: solo hay 3 workers de Horizon en total
        // (seccion 2) y 'fetch' no es la cola de mayor prioridad.
        $response = Http::timeout(15)->retry(2, 500)->get($this->url)->throw();
        $html = $response->body();

        $fechaPublicacion = $this->extraerFechaPublicacion($html);

        $ventanaDias = (int) config('vera.article_window_days');
        if ($fechaPublicacion !== null && $fechaPublicacion->lt(CarbonImmutable::now()->subDays($ventanaDias))) {
            // Fuera de la ventana configurada (seccion 3.4): se descarta,
            // no se persiste evidencia de algo que no se va a usar.
            return null;
        }

        $hashContenido = hash('sha256', $html);
        $evidencePath = "articles/{$hashContenido}.html";
        Storage::put($evidencePath, $html);

        try {
            $article = Article::create([
                'url' => $this->url,
                'titulo' => $this->extraerTitulo($html),
                'medio' => parse_url($this->url, PHP_URL_HOST) ?: null,
                'fecha_publicacion' => $fechaPublicacion,
                'hash_contenido' => $hashContenido,
                'evidence_path' => $evidencePath,
                'estado_extraccion' => 'pendiente',
            ]);

            ExtractEntitiesJob::dispatch($article->id);

            return $article;
        } catch (UniqueConstraintViolationException) {
            /**
             * El exists() de arriba no es atomico: dos dispatches para la
             * misma url en workers distintos pueden pasarlo los dos antes
             * de que cualquiera inserte (TOCTOU). En vez de tronar con una
             * QueryException sin manejar, se trata como el no-op que
             * deberia ser - la url ya quedo cubierta por el otro worker.
             */
            return null;
        }
    }

    /**
     * Intenta, en orden: meta article:published_time, JSON-LD (datePublished),
     * <time datetime="...">. Selectores especificos por medio se calibran
     * en Fase 0 (seccion 5, punto 2) - esto es el fallback generico.
     */
    private function extraerFechaPublicacion(string $html): ?CarbonImmutable
    {
        $crawler = new Crawler($html);

        $metaNode = $crawler->filter('meta[property="article:published_time"]');
        if ($metaNode->count() > 0) {
            $value = $metaNode->attr('content');
            if ($value !== null && ($fecha = $this->parseFecha($value)) !== null) {
                return $fecha;
            }
        }

        foreach ($crawler->filter('script[type="application/ld+json"]') as $node) {
            $json = json_decode($node->textContent, true);
            $datePublished = is_array($json) ? ($json['datePublished'] ?? null) : null;
            if (is_string($datePublished) && ($fecha = $this->parseFecha($datePublished)) !== null) {
                return $fecha;
            }
        }

        $timeNode = $crawler->filter('time[datetime]');
        if ($timeNode->count() > 0) {
            $value = $timeNode->attr('datetime');
            if ($value !== null && ($fecha = $this->parseFecha($value)) !== null) {
                return $fecha;
            }
        }

        return null;
    }

    private function parseFecha(string $value): ?CarbonImmutable
    {
        try {
            return CarbonImmutable::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function extraerTitulo(string $html): ?string
    {
        $crawler = new Crawler($html);
        $titleNode = $crawler->filter('title');

        return $titleNode->count() > 0 ? trim($titleNode->text()) : null;
    }
}
