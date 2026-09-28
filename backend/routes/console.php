<?php

use App\Jobs\ActualizarListaOfacProgramadaJob;
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

/*
| Listas de sanciones (seccion 3.4): importacion semanal de OFAC SDN (descarga
| publica y gratuita) y, solo si la importacion termino bien, cruce de los
| subjects activos de los tenants con Sanciones habilitada (MatchSanctionsJob
| ya salta el resto). Ningun servicio de pago (seccion 7).
| ActualizarListaOfacProgramadaJob solo dispara si el modo de descarga
| (panel de superadmin) es 'automatico'.
*/
Schedule::job(new ActualizarListaOfacProgramadaJob)
    ->weeklyOn(0, '02:00')
    ->timezone(config('vera.zona_horaria'))
    ->onOneServer();
