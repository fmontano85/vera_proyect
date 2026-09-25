<?php

declare(strict_types=1);

namespace App\Actions\Subjects;

use App\Models\Subject;
use App\Services\Matching\IndiceSubjects;
use Illuminate\Support\Facades\DB;

/**
 * Edicion del subject: datos basicos (nombre, tipo, documento), estado
 * activo y agenda de seguimiento (nivel, frecuencia - seccion 3.8). El
 * recalculo de proximo_seguimiento_en y la auditoria los hace el propio
 * modelo. Si cambia algo que esta en el indice (nombre, activo), se
 * reindexa dentro de la transaccion: si Meilisearch falla, no se guarda.
 */
class ActualizarSubject
{
    public function __construct(private readonly IndiceSubjects $indice) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(Subject $subject, array $data): Subject
    {
        return DB::transaction(function () use ($subject, $data) {
            Subject::withoutSyncingToSearch(fn () => $subject->update($data));

            if ($subject->wasChanged(['nombre_canonico', 'activo'])) {
                $this->indice->reindexar($subject);
            }

            return $subject;
        });
    }
}
