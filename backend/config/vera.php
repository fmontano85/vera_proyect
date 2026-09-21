<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Parametros de negocio de VERA
|--------------------------------------------------------------------------
|
| Umbrales y ventanas del pipeline (seccion 8 del CLAUDE.md raiz). Varios
| siguen sin calibrar hasta que corra la Fase 0 (seccion 5, 9) - el default
| aqui es el que ya estaba documentado, no un valor inventado en este archivo.
|
*/

return [
    'article_window_days' => (int) env('ARTICLE_WINDOW_DAYS', 30),
    'extraction_confidence_threshold' => (float) env('EXTRACTION_CONFIDENCE_THRESHOLD', 0.6),
];
