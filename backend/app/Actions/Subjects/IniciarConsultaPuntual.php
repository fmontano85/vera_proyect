<?php

declare(strict_types=1);

namespace App\Actions\Subjects;

use App\Jobs\RunSubjectSearchJob;
use App\Models\Source;
use App\Models\Subject;
use RuntimeException;

/**
 * Consulta puntual (seccion 1.1 y 5 "Fase 1" del CLAUDE.md raiz): dispara el
 * pipeline completo (seccion 3.4) para un subject contra todas las fuentes
 * activas que ya tienen adaptador implementado. Desde 2026-09-24 eso es
 * 'brave' (BraveSearchAdapter, reemplaza a Google CSE - ver CLAUDE.md
 * raiz, Estatus de sesion). RunSubjectSearchJob::adapterFor() lanza
 * InvalidArgumentException para 'rss'/'oficial' porque esos adaptadores
 * todavia no existen (ver "Pendiente" del CLAUDE.md), asi que filtrar aqui
 * evita encolar un job que solo va a fallar.
 */
class IniciarConsultaPuntual
{
    /**
     * @return list<int> ids de las sources contra las que se encolo la busqueda
     */
    public function handle(Subject $subject): array
    {
        $sources = Source::query()
            ->where('activo', true)
            ->where('tipo', 'brave')
            ->pluck('id');

        if ($sources->isEmpty()) {
            throw new RuntimeException(
                'No hay fuentes activas de tipo brave configuradas. Crea una Source con tipo=brave antes de ejecutar una consulta puntual.'
            );
        }

        foreach ($sources as $sourceId) {
            RunSubjectSearchJob::dispatch($subject->id, $sourceId);
        }

        return $sources->all();
    }
}
