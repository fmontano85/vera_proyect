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

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,

];
