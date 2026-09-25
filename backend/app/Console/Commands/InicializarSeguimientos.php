<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\FrecuenciaSeguimiento;
use App\Models\Subject;
use App\Services\Seguimiento\CalculadoraSeguimiento;
use Illuminate\Console\Command;
use Stancl\Tenancy\Database\Models\Tenant;

/**
 * Seccion 3.8: tenants y subjects creados antes de la agenda de
 * seguimiento no tienen frecuencias sembradas ni proximo_seguimiento_en.
 * Idempotente: no pisa frecuencias ya configuradas ni fechas ya
 * calculadas. Inicializa tenancy explicitamente por tenant (seccion 6:
 * BelongsToTenant falla abierto fuera de un request).
 */
class InicializarSeguimientos extends Command
{
    protected $signature = 'vera:inicializar-seguimientos';

    protected $description = 'Siembra frecuencias de seguimiento y calcula la proxima fecha de los subjects existentes (seccion 3.8).';

    public function handle(CalculadoraSeguimiento $calculadora): int
    {
        foreach (Tenant::all() as $tenant) {
            tenancy()->initialize($tenant);

            FrecuenciaSeguimiento::sembrarDefaults();
            $frecuencias = $calculadora->frecuenciasDelTenant($tenant->getTenantKey());

            $actualizados = 0;
            Subject::whereNull('proximo_seguimiento_en')->each(function (Subject $subject) use ($calculadora, $frecuencias, &$actualizados) {
                $subject->forceFill([
                    'proximo_seguimiento_en' => $calculadora->calcularProximo($subject, $frecuencias),
                ])->saveQuietly();
                $actualizados++;
            });

            $this->line("Tenant {$tenant->getTenantKey()}: {$actualizados} subject(s) con fecha de seguimiento calculada.");

            tenancy()->end();
        }

        return self::SUCCESS;
    }
}
