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
    'article_window_days' => (int) env('ARTICLE_WINDOW_DAYS', 60),
    'extraction_confidence_threshold' => (float) env('EXTRACTION_CONFIDENCE_THRESHOLD', 0.6),

    /**
     * Disco para el PDF de evidencia de captura manual (seccion 3.7 del
     * CLAUDE.md raiz) - a proposito INDEPENDIENTE de FILESYSTEM_DISK (que
     * en produccion es 'r2' para la evidencia automatica de 'articles',
     * seccion 3.6). El usuario pidio explicitamente poder dejarlo en local
     * tanto en dev como en produccion, configurable sin depender de nada
     * mas - por eso su propia variable, no un disco de negocio en general.
     */
    'evidencia_manual_disk' => env('EVIDENCIA_MANUAL_DISK', 'local'),
];
