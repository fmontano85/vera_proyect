<?php

use App\Jobs\DetectarSeguimientosVencidosJob;
use App\Jobs\ReconciliarIndiceSubjectsJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
| Seccion 3.8 del CLAUDE.md raiz: agenda de seguimiento. Solo consulta la
| BD propia; ningun job programado llama a Brave ni a Anthropic (seccion 7).
| Lo ejecuta 'schedule:work' (docker/api/supervisord.conf).
*/
Schedule::job(new DetectarSeguimientosVencidosJob)
    ->dailyAt('07:00')
    ->timezone(config('vera.zona_horaria'));

// Red de seguridad del indice de Meilisearch (self-hosted, sin costo):
// reenvia activos/inactivos segun la BD. Ver ReconciliarIndiceSubjectsJob.
Schedule::job(new ReconciliarIndiceSubjectsJob)
    ->dailyAt('03:00')
    ->timezone(config('vera.zona_horaria'));
