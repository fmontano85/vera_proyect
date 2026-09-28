<?php

declare(strict_types=1);

/**
 * retry_after de la cola Redis debe superar el timeout de todo job: si no,
 * Redis vuelve a entregar un job que sigue corriendo a un segundo worker
 * (con tries=1 lo marca fallido mientras el primero lo termina). Hallazgo
 * del code-review 2026-09-28.
 */
it('ningun job tiene un timeout mayor o igual al retry_after de la cola Redis', function () {
    $retryAfter = (int) config('queue.connections.redis.retry_after');

    $excedidos = collect(glob(app_path('Jobs/*.php')))
        ->map(fn (string $archivo) => 'App\\Jobs\\'.basename($archivo, '.php'))
        ->filter(fn (string $clase) => (new ReflectionClass($clase))->hasProperty('timeout'))
        ->mapWithKeys(fn (string $clase) => [$clase => (int) (new ReflectionClass($clase))->getProperty('timeout')->getDefaultValue()])
        ->filter(fn (int $timeout) => $timeout >= $retryAfter);

    expect($excedidos->all())->toBe([]);
});
