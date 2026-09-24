<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Data\Extraction\ExtractionResultData;
use App\Enums\EstadoSearchResult;
use App\Models\Article;
use App\Models\Extraction;
use App\Models\Mention;
use App\Models\SearchResult;
use App\Services\Extraction\AnthropicClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * extractions/mentions son globales (sin tenant_id) - ver Article/Mention.
 * Contrato de salida y reglas de escalado: seccion 3.5 del CLAUDE.md raiz.
 * Requiere ANTHROPIC_API_KEY (config/services.php) para la llamada real;
 * el codigo esta completo, solo falta esa variable en backend/.env.
 *
 * $searchResultId (seccion 3.7, 2026-09-24): opcional para no romper la
 * firma en otros contextos, pero FetchArticleJob siempre lo manda ahora -
 * al terminar marca ese search_result como 'extraido' o 'sin_menciones'.
 */
class ExtractEntitiesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        private readonly int $articleId,
        private readonly ?int $searchResultId = null,
    ) {
        $this->onQueue('extraction');
    }

    public function handle(AnthropicClient $client): void
    {
        $article = Article::findOrFail($this->articleId);

        /**
         * Idempotencia (seccion 7): si el articulo ya no esta 'pendiente'
         * (completado o fallido en un intento anterior con retry
         * agotado), no se vuelve a pagar la llamada a Anthropic ni a
         * reprocesar. No cubre la ventana exacta entre "ya se creo la
         * extraction de Haiku" y "todavia no se marco completado" -
         * mentions tiene su propia unica (article_id, nombre_extraido,
         * rol) + firstOrCreate() como segunda capa contra ese caso.
         */
        if ($article->estado_extraccion !== 'pendiente') {
            return;
        }

        $texto = $this->textoLimpio($article);
        $prompt = $this->construirPrompt($texto);

        $modeloRapido = config('services.anthropic.model_fast');
        $primeraCorrida = $client->extraer($prompt, $modeloRapido);

        $extraction = $this->guardarExtraction($article, $modeloRapido, $primeraCorrida);
        $resultadoFinal = $primeraCorrida['resultado'];

        if ($this->debeEscalar($resultadoFinal)) {
            $modeloEscalado = config('services.anthropic.model_escalation');
            $segundaCorrida = $client->extraer($prompt, $modeloEscalado);
            $extraction = $this->guardarExtraction($article, $modeloEscalado, $segundaCorrida);
            $resultadoFinal = $segundaCorrida['resultado'];
        }

        $this->crearMentions($article, $resultadoFinal);

        $article->update(['estado_extraccion' => 'completado']);

        if ($this->searchResultId !== null) {
            SearchResult::whereKey($this->searchResultId)->update([
                'estado' => $resultadoFinal->personas === []
                    ? EstadoSearchResult::SinMenciones
                    : EstadoSearchResult::Extraido,
            ]);
        }
    }

    private function textoLimpio(Article $article): string
    {
        $html = $article->evidence_path ? Storage::get($article->evidence_path) : '';

        return trim(strip_tags((string) preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', ' ', (string) $html)));
    }

    private function construirPrompt(string $texto): string
    {
        $plantilla = file_get_contents(resource_path('prompts/extraction/v1.md'));

        return str_replace('{{articulo}}', $texto, $plantilla);
    }

    /**
     * @param array{resultado: ExtractionResultData, tokens_in: int, tokens_out: int} $corrida
     */
    private function guardarExtraction(Article $article, string $modelo, array $corrida): Extraction
    {
        return Extraction::create([
            'article_id' => $article->id,
            'modelo' => $modelo,
            'json_resultado' => $corrida['resultado']->toArray(),
            'confianza' => $corrida['resultado']->confianza_global,
            'tokens_in' => $corrida['tokens_in'],
            'tokens_out' => $corrida['tokens_out'],
            // Costo en USD por token no se calcula aqui: requiere la
            // tarifa vigente por modelo, que no se va a inventar - se deja
            // null hasta tener las tarifas reales confirmadas.
            'costo' => null,
        ]);
    }

    /**
     * Escala a Sonnet si la confianza global es baja, o si hay mas de 3
     * personas con roles distintos entre si (seccion 3.5).
     */
    private function debeEscalar(ExtractionResultData $resultado): bool
    {
        if ($resultado->confianza_global < config('vera.extraction_confidence_threshold')) {
            return true;
        }

        $roles = collect($resultado->personas)->pluck('rol')->unique();

        return count($resultado->personas) > 3 && $roles->count() > 1;
    }

    private function crearMentions(Article $article, ExtractionResultData $resultado): void
    {
        foreach ($resultado->personas as $persona) {
            $mention = Mention::firstOrCreate(
                [
                    'article_id' => $article->id,
                    'nombre_extraido' => $persona->nombre,
                    'rol' => $persona->rol->value,
                ],
                [
                    'search_result_id' => $this->searchResultId,
                    'delitos' => $persona->delitos,
                    'confianza' => $persona->confianza,
                ],
            );

            if ($mention->wasRecentlyCreated) {
                MatchMentionsJob::dispatch($mention->id);
            }
        }
    }

    /**
     * Horizon llama esto cuando el job agota sus reintentos - es el lugar
     * correcto para marcar 'fallido' (no un try/catch dentro de handle()),
     * porque solo se ejecuta cuando ya no va a haber otro intento.
     */
    public function failed(Throwable $exception): void
    {
        Article::find($this->articleId)?->update(['estado_extraccion' => 'fallido']);
    }
}
