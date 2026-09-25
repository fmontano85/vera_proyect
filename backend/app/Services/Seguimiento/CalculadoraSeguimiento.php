<?php

declare(strict_types=1);

namespace App\Services\Seguimiento;

use App\Models\FrecuenciaSeguimiento;
use App\Models\Subject;
use Carbon\CarbonImmutable;

/**
 * Reglas de la agenda de seguimiento (seccion 3.8 del CLAUDE.md raiz):
 * - frecuencia efectiva = la del subject si existe; si no, la de su
 *   nivel de riesgo configurada por el tenant ('sin_nivel' si no tiene).
 * - proximo = (ultimo seguimiento, o fecha de alta si nunca tuvo) + dias,
 *   en fechas de calendario de la zona horaria de VERA.
 */
class CalculadoraSeguimiento
{
    public function hoy(): CarbonImmutable
    {
        return CarbonImmutable::now($this->zona())->startOfDay();
    }

    /**
     * Filtra por tenant_id explicito (ademas del scope de BelongsToTenant):
     * si alguien calcula fuera de un request con tenancy inicializada, no
     * mezcla la configuracion de otros tenants - falla cerrado a los
     * DEFAULTS en vez de abierto.
     *
     * @return array<string, int>
     */
    public function frecuenciasDelTenant(?string $tenantId): array
    {
        $configuradas = FrecuenciaSeguimiento::query()
            ->where('tenant_id', $tenantId)
            ->pluck('dias', 'nivel_riesgo')
            ->map(fn ($dias) => (int) $dias)
            ->all();

        return $configuradas + FrecuenciaSeguimiento::DEFAULTS;
    }

    /**
     * @param  array<string, int>|null  $frecuencias  precargadas (listados, evita N+1)
     * @return array{dias: int, origen: 'personalizada'|'nivel'}
     */
    public function frecuenciaEfectiva(Subject $subject, ?array $frecuencias = null): array
    {
        if ($subject->frecuencia_seguimiento_dias !== null) {
            return ['dias' => (int) $subject->frecuencia_seguimiento_dias, 'origen' => 'personalizada'];
        }

        $frecuencias ??= $this->frecuenciasDelTenant($subject->tenant_id ?? tenant('id'));

        return ['dias' => $frecuencias[$subject->nivel_riesgo ?? 'sin_nivel'], 'origen' => 'nivel'];
    }

    /**
     * @param  array<string, int>|null  $frecuencias
     */
    public function calcularProximo(Subject $subject, ?array $frecuencias = null): CarbonImmutable
    {
        $base = CarbonImmutable::parse($subject->ultimo_seguimiento_en ?? $subject->created_at ?? now())
            ->setTimezone($this->zona())
            ->startOfDay();

        return $base->addDays($this->frecuenciaEfectiva($subject, $frecuencias)['dias']);
    }

    /**
     * Bloque 'seguimiento' que se agrega al JSON del subject.
     *
     * @param  array<string, int>|null  $frecuencias
     * @return array<string, mixed>
     */
    public function resumen(Subject $subject, ?array $frecuencias = null): array
    {
        $frecuencia = $this->frecuenciaEfectiva($subject, $frecuencias);
        $proximo = $subject->proximo_seguimiento_en;

        return [
            'frecuencia_dias' => $frecuencia['dias'],
            'origen_frecuencia' => $frecuencia['origen'],
            'proximo_seguimiento_en' => $proximo?->toDateString(),
            'vencido' => $proximo !== null && $proximo->toDateString() <= $this->hoy()->toDateString(),
            'ultimo_seguimiento_en' => $subject->ultimo_seguimiento_en?->toIso8601String(),
            'ultimo_seguimiento_por' => $subject->ultimoSeguimientoUsuario === null ? null : [
                'id' => $subject->ultimoSeguimientoUsuario->id,
                'name' => $subject->ultimoSeguimientoUsuario->name,
            ],
        ];
    }

    /**
     * JSON unico de un subject con su bloque de seguimiento (detalle,
     * PATCH, panel y "seguimiento realizado" responden igual).
     *
     * @param  array<string, int>|null  $frecuencias
     * @return array<string, mixed>
     */
    public function serializar(Subject $subject, ?array $frecuencias = null): array
    {
        $subject->loadMissing('ultimoSeguimientoUsuario');

        return $subject->toArray() + ['seguimiento' => $this->resumen($subject, $frecuencias)];
    }

    private function zona(): string
    {
        return (string) config('vera.zona_horaria');
    }
}
