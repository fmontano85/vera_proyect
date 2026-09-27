<?php

declare(strict_types=1);

/**
 * Nunca se habia publicado (Laravel corria con los defaults del framework,
 * que traen supports_credentials=false) - Sanctum SPA por cookies (seccion 2
 * del CLAUDE.md raiz) necesita esto explicito o el navegador nunca manda/
 * recibe la cookie de sesion ni la de CSRF entre localhost:5173 (frontend)
 * y localhost:8000 (backend).
 */
return [

    'paths' => ['api/*', 'sanctum/csrf-cookie', 'login', 'logout'],

    'allowed_methods' => ['*'],

    'allowed_origins' => [env('FRONTEND_URL', 'http://localhost:5173')],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    /**
     * Content-Disposition: sin exponerla, el fetch del frontend
     * (lib/api.ts download()) nunca puede leer el nombre de archivo que
     * manda el backend en un navegador real (fetch cross-origin solo
     * expone los headers "CORS-safelisted" salvo que se listen aqui) -
     * la descarga funciona pero se guarda sin nombre ni extension.
     */
    'exposed_headers' => ['Content-Disposition'],

    'max_age' => 0,

    'supports_credentials' => true,

];
