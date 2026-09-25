<?php

declare(strict_types=1);

namespace App\Actions\Seguimiento;

use App\Models\FrecuenciaSeguimiento;
use App\Models\Subject;
use App\Services\Seguimiento\CalculadoraSeguimiento;
use Illuminate\Support\Facades\DB;

/**
 * Seccion 3.8: el admin cambia los dias por nivel de riesgo del tenant.
 * Cada cambio queda auditado (LogsActivity de FrecuenciaSeguimiento) y
 * se recalcula la proxima fecha de los subjects que usan el default de
 * su nivel (los que tienen frecuencia propia no cambian).
 */
class ActualizarFrecuenciasSeguimiento
{
    public function __construct(private readonly CalculadoraSeguimiento $calculadora) {}

    /**
     * @param  array<string, int>  $dias  por nivel (FrecuenciaSeguimiento::NIVELES)
     * @return array<string, int>
     */
    public function handle(array $dias): array
    {
        return DB::transaction(function () use ($dias) {
            foreach (FrecuenciaSeguimiento::NIVELES as $nivel) {
                FrecuenciaSeguimiento::updateOrCreate(['nivel_riesgo' => $nivel], ['dias' => $dias[$nivel]]);
            }

            $frecuencias = $this->calculadora->frecuenciasDelTenant(tenant('id'));

            Subject::whereNull('frecuencia_seguimiento_dias')->each(function (Subject $subject) use ($frecuencias) {
                $subject->forceFill([
                    'proximo_seguimiento_en' => $this->calculadora->calcularProximo($subject, $frecuencias),
                ])->saveQuietly();
            });

            return $frecuencias;
        });
    }
}
