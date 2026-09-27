<?php

use App\Jobs\DetectarSeguimientosVencidosJob;
use App\Jobs\ImportSanctionListsJob;
use App\Jobs\MatchSanctionsJob;
use App\Jobs\ReconciliarIndiceSubjectsJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
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
| publica y gratuita) y, solo si la importacion termino bien, cruce de todos los
| subjects activos. Ningun servicio de pago (seccion 7).
*/
Schedule::call(fn () => Bus::chain([
    new ImportSanctionListsJob('ofac_sdn'),
    new MatchSanctionsJob,
])->dispatch())
    ->name('sanciones-importar-y-cruzar')
    ->weeklyOn(0, '02:00')
    ->timezone(config('vera.zona_horaria'))
    ->onOneServer();
