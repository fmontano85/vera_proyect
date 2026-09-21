<?php

declare(strict_types=1);

namespace App\Services\Extraction;

use App\Data\Extraction\ExtractionResultData;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Cliente minimo de la Messages API de Anthropic (seccion 3.5/8 del
 * CLAUDE.md raiz). Requiere ANTHROPIC_API_KEY en backend/.env (ver
 * config/services.php) - sin ella, Anthropic responde 401; el llamado
 * esta completo y listo para usarse en cuanto la key este puesta.
 */
class AnthropicClient
{
    private const URL = 'https://api.anthropic.com/v1/messages';

    private const ANTHROPIC_VERSION = '2023-06-01';

    /**
     * @return array{resultado: ExtractionResultData, tokens_in: int, tokens_out: int}
     */
    public function extraer(string $prompt, string $modelo): array
    {
        $response = Http::timeout(60)
            ->withHeaders([
                'x-api-key' => config('services.anthropic.api_key'),
                'anthropic-version' => self::ANTHROPIC_VERSION,
            ])
            ->post(self::URL, [
                'model' => $modelo,
                'max_tokens' => 2048,
                'messages' => [
                    ['role' => 'user', 'content' => $prompt],
                ],
            ])
            ->throw()
            ->json();

        $texto = $response['content'][0]['text'] ?? null;

        if (! is_string($texto)) {
            throw new RuntimeException('Respuesta de Anthropic sin contenido de texto utilizable.');
        }

        $json = json_decode($this->limpiarBloqueMarkdown($texto), true);

        if (! is_array($json)) {
            throw new RuntimeException('La respuesta de Anthropic no es JSON valido: '.$texto);
        }

        return [
            'resultado' => ExtractionResultData::from($json),
            'tokens_in' => $response['usage']['input_tokens'] ?? 0,
            'tokens_out' => $response['usage']['output_tokens'] ?? 0,
        ];
    }

    /**
     * Claude a veces envuelve el JSON en un bloque ```json ... ``` aunque
     * se le pida que no lo haga - se limpia antes de decodificar.
     */
    private function limpiarBloqueMarkdown(string $texto): string
    {
        $texto = trim($texto);
        $texto = preg_replace('/^```(?:json)?/', '', $texto);
        $texto = preg_replace('/```$/', '', $texto);

        return trim((string) $texto);
    }
}
