<?php

declare(strict_types=1);

namespace App\Actions\Subjects;

use App\Models\Subject;

/**
 * Seccion 3.8: cambiar nivel de riesgo o frecuencia personalizada. El
 * recalculo de proximo_seguimiento_en y la auditoria los hace el propio
 * modelo (hook de Subject + LogsActivity).
 */
class ActualizarSubject
{
    /**
     * @param  array{nivel_riesgo?: ?string, frecuencia_seguimiento_dias?: ?int}  $data
     */
    public function handle(Subject $subject, array $data): Subject
    {
        $subject->update($data);

        return $subject;
    }
}
