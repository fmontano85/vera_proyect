<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\EstadoSearchResult;
use App\Enums\GapMotivo;
use App\Models\Article;
use App\Models\Mention;
use App\Models\SearchResult;
use App\Services\Search\VentanaTemporal;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\DomCrawler\Crawler;
use Throwable;

/**
 * articles es global (sin tenant_id, ver seccion 3.1 del CLAUDE.md raiz).
 *
 * Flujo bajo demanda (seccion 3.7, 2026-09-24): solo se encola por accion
 * del analista (App\Actions\SearchResults\ExtraerResultado), no automatico.
 * Recibe un search_result_id, no una URL suelta. Un fallo esperado (403,
 * timeout, contenido vacio, no-HTML, fuera de ventana) marca el
 * search_result como GAP con su motivo y TERMINA SIN EXCEPCION - un GAP
 * es un resultado valido que admite captura manual, no un fallo de job
 * que Horizon deba reintentar.
 */
class FetchArticleJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(private readonly int $searchResultId)
    {
        $this->onQueue('fetch');
    }

    public function handle(): void
    {
        $searchResult = SearchResult::findOrFail($this->searchResultId);
        $urlHash = hash('sha256', $searchResult->url);

        // Articles es global y deduplicado por url (seccion 3.1) - si
        // otro subject (o esta misma busqueda en otro dia) ya lo bajo y
        // extrajo, no se vuelve a descargar ni a pagar otra extraccion.
        $existente = Article::where('url_hash', $urlHash)->first();
        if ($existente !== null) {
            $this->reutilizarArticuloExistente($searchResult, $existente);

            return;
        }

        try {
            // timeout/retry moderados: solo hay 3 workers de Horizon en
            // total (seccion 2) y 'fetch' no es la cola de mayor prioridad.
            $response = Http::timeout(15)->retry(2, 500)->get($searchResult->url)->throw();
        } catch (RequestException $e) {
            $status = $e->response->status();
            $this->marcarGap($searchResult, $status === 403 ? GapMotivo::Http403 : GapMotivo::HttpError, $status);

            return;
        } catch (ConnectionException) {
            $this->marcarGap($searchResult, GapMotivo::Timeout);

            return;
        }

        $contentType = (string) $response->header('Content-Type');
        if ($contentType !== '' && ! str_contains($contentType, 'html')) {
            // Ej. un PDF que Brave a veces devuelve mezclado con noticias.
            $this->marcarGap($searchResult, GapMotivo::NoHtml, $response->status());

            return;
        }

        $html = $response->body();
        // strip_tags() NO borra el contenido de <script>/<style>, solo la
        // etiqueta - una pagina que es puro <script> pasaria como "con
        // contenido" sin este paso primero (mismo patron que
        // ExtractEntitiesJob::textoLimpio()).
        $textoVisible = strip_tags((string) preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', ' ', $html));
        if (trim($textoVisible) === '') {
            $this->marcarGap($searchResult, GapMotivo::SinContenido, $response->status());

            return;
        }

        $fechaPublicacion = $this->extraerFechaPublicacion($html);

        $ventanaDias = VentanaTemporal::diasPara($searchResult);
        if ($fechaPublicacion !== null && $fechaPublicacion->lt(CarbonImmutable::now()->subDays($ventanaDias))) {
            $this->marcarGap($searchResult, GapMotivo::FueraDeVentana, $response->status());

            return;
        }

        $hashContenido = hash('sha256', $html);
        $evidencePath = "articles/{$hashContenido}.html";
        Storage::put($evidencePath, $html);

        try {
            $article = Article::create([
                'url' => $searchResult->url,
                'titulo' => $this->extraerTitulo($html) ?? $searchResult->titulo,
                'medio' => parse_url($searchResult->url, PHP_URL_HOST) ?: null,
                'fecha_publicacion' => $fechaPublicacion,
                'hash_contenido' => $hashContenido,
                'evidence_path' => $evidencePath,
                'estado_extraccion' => 'pendiente',
            ]);
        } catch (UniqueConstraintViolationException) {
            /**
             * El exists() de arriba no es atomico: dos dispatches para la
             * misma url en workers distintos pueden pasarlo los dos antes
             * de que cualquiera inserte (TOCTOU). Se trata como el caso de
             * "articulo ya existente", no como un fallo.
             */
            $this->reutilizarArticuloExistente($searchResult, Article::where('url_hash', $urlHash)->firstOrFail());

            return;
        }

        $searchResult->forceFill(['article_id' => $article->id])->save();

        ExtractEntitiesJob::dispatch($article->id, $searchResult->id);
    }

    /**
     * El articulo ya existe (de otro subject, o de una busqueda anterior
     * de este mismo). Si ya tiene mentions extraidas, no hace falta
     * volver a pagar Anthropic - solo hace falta re-chequear el match
     * contra ESTE subject (MatchMentionsJob es idempotente por
     * mention_id+subject_id via el unique de 'matches', asi que
     * redespacharlo no duplica nada).
     */
    private function reutilizarArticuloExistente(SearchResult $searchResult, Article $article): void
    {
        $searchResult->forceFill(['article_id' => $article->id])->save();

        if ($article->estado_extraccion !== 'completado') {
            // Extraccion todavia en curso o fallida - se deja que termine
            // (o se reintenta) por su propio camino; caso raro (dos
            // subjects distintos pidiendo "extraer" sobre la misma URL
            // nueva casi al mismo tiempo), no se optimiza mas por ahora.
            ExtractEntitiesJob::dispatch($article->id, $searchResult->id);

            return;
        }

        $mentions = Mention::where('article_id', $article->id)->get();

        if ($mentions->isEmpty()) {
            $searchResult->forceFill(['estado' => EstadoSearchResult::SinMenciones])->save();

            return;
        }

        $searchResult->forceFill(['estado' => EstadoSearchResult::Extraido])->save();

        foreach ($mentions as $mention) {
            MatchMentionsJob::dispatch($mention->id);
        }
    }

    private function marcarGap(SearchResult $searchResult, GapMotivo $motivo, ?int $httpStatus = null): void
    {
        $searchResult->forceFill([
            'estado' => EstadoSearchResult::Gap,
            'gap_motivo' => $motivo,
            'http_status' => $httpStatus,
        ])->save();
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
        } catch (Throwable) {
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
