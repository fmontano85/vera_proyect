<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Subject;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Stancl\Tenancy\Database\Models\Tenant;

/**
 * Red de seguridad del indice 'subjects' de Meilisearch (code-review
 * 2026-09-25). IndiceSubjects reindexa dentro de la transaccion y revierte
 * si Meilisearch no responde, pero hay dos casos que no puede atrapar:
 * - Meilisearch indexa de forma ASINCRONA: la llamada vuelve al encolar la
 *   tarea (202); si la tarea falla despues, nadie se entera.
 * - El indice se escribe antes del commit: si el commit falla, el indice
 *   queda adelantado a la BD.
 * Una vez al dia se reenvia el estado real de la BD: activos al indice,
 * inactivos fuera. Asi el desfase dura como maximo un dia.
 *
 * Solo toca Meilisearch (self-hosted, sin costo por uso) - la regla de la
 * seccion 7 prohibe jobs programados contra servicios de PAGO (Brave,
 * Anthropic), no contra la infraestructura propia.
 */
class ReconciliarIndiceSubjectsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct()
    {
        $this->onQueue('imports');
    }

    public function handle(): void
    {
        tenancy()->runForMultiple(null, function (Tenant $tenant) {
            Subject::query()
                ->with('aliases')
                ->chunkById(200, function ($subjects) {
                    $subjects->where('activo', true)->values()->searchable();
                    $subjects->where('activo', false)->values()->unsearchable();
                });
        });
    }
}
