<?php

declare(strict_types=1);

namespace App\Actions\Seguimiento;

use App\Models\Subject;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Seccion 3.8: el usuario cierra el seguimiento a mano (ejecutar la
 * consulta puntual NO lo cierra). El hook de Subject recalcula
 * proximo_seguimiento_en al cambiar ultimo_seguimiento_en. Queda en
 * activity_log con la observacion (seccion 7 + seccion 9: constancia
 * ante revision de la UIF).
 */
class MarcarSeguimientoRealizado
{
    public function handle(Subject $subject, User $user, ?string $observacion): Subject
    {
        return DB::transaction(function () use ($subject, $user, $observacion) {
            $subject->forceFill([
                'ultimo_seguimiento_en' => now(),
                'ultimo_seguimiento_por' => $user->id,
            ])->save();

            activity()
                ->performedOn($subject)
                ->causedBy($user)
                ->event('seguimiento_realizado')
                ->withProperties([
                    'observacion' => $observacion,
                    'proximo_seguimiento_en' => $subject->proximo_seguimiento_en?->toDateString(),
                ])
                ->log('Seguimiento realizado');

            return $subject;
        });
    }
}
