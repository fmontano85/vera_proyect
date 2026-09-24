<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'google_cse' => [
        'api_key' => env('GOOGLE_CSE_API_KEY'),
        'cx' => env('GOOGLE_CSE_CX'),
        'daily_limit' => (int) env('GOOGLE_CSE_DAILY_LIMIT', 100),
    ],

    /**
     * Fuente activa por default desde 2026-09-24 (ver CLAUDE.md raiz,
     * Estatus de sesion): Google CSE quedo bloqueado por facturacion y,
     * ademas, Google cierra esa API por completo el 1 de enero de 2027.
     * google_cse arriba se queda en el codigo por si se retoma antes de
     * esa fecha.
     */
    'brave_search' => [
        'api_key' => env('BRAVE_SEARCH_API_KEY'),
        // Cuota MENSUAL (asi factura Brave: $5/1000 requests). Desde
        // feb-2026 Brave ya no tiene tier gratuito real - da $5 de credito
        // automatico cada mes (~1000 requests), con tarjeta obligatoria
        // como instrumento de cobro activo y SIN tope de gasto de su
        // lado. Default en 1000 (confirmado con el usuario 2026-09-24)
        // para quedar exacto en $0 mientras no se decida pagar mas - el
        // unico tope real es este candado, Brave no impone ninguno.
        'monthly_limit' => (int) env('BRAVE_SEARCH_MONTHLY_LIMIT', 1000),
    ],

    'anthropic' => [
        'api_key' => env('ANTHROPIC_API_KEY'),
        'model_fast' => env('ANTHROPIC_MODEL_FAST', 'claude-haiku-4-5-20251001'),
        'model_escalation' => env('ANTHROPIC_MODEL_ESCALATION', 'claude-sonnet-5'),
    ],

];
