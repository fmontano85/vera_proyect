<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();

        $middleware->alias([
            'tenant' => \App\Http\Middleware\InitializeTenancyFromAuthenticatedUser::class,
        ]);

        // SubstituteBindings (binding implicito de modelos en rutas, ej.
        // "Subject $subject") corre antes que cualquier middleware nombrado
        // sin prioridad explicita. Sin esto, un modelo con global scope de
        // tenant_id se resuelve ANTES de que el tenant este inicializado -
        // fuga de datos entre tenants via route-model-binding.
        $middleware->prependToPriorityList(
            before: \Illuminate\Routing\Middleware\SubstituteBindings::class,
            prepend: \App\Http\Middleware\InitializeTenancyFromAuthenticatedUser::class,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
