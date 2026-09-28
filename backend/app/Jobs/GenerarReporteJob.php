<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Report;
use App\Models\Subject;
use App\Services\Evidence\GeneradorPdf;
use App\Services\Reportes\ConstructorReportes;
use App\Services\Reportes\EscritorCsv;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Stancl\Tenancy\Database\Models\Tenant;
use Throwable;

/**
 * Genera un reporte de auditoria (seccion 1, punto 5) en segundo plano:
 * un reporte grande no corta la conexion HTTP. Solo BD propia y Storage;
 * ningun servicio de pago (seccion 7). Idempotente: un reporte ya listo
 * no se regenera.
 */
class GenerarReporteJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(public readonly int $reporteId, public readonly string $tenantId)
    {
        $this->onQueue('imports');
    }

    public function handle(ConstructorReportes $constructor, GeneradorPdf $pdf): void
    {
        $tenant = Tenant::find($this->tenantId);
        if ($tenant === null) {
            return;
        }

        $tenant->run(function () use ($constructor, $pdf) {
            $reporte = Report::query()->with('generadoPor')->find($this->reporteId);
            if ($reporte === null || $reporte->estado === 'listo') {
                return;
            }

            $reporte->forceFill(['estado' => 'generando', 'error' => null])->save();

            $contenido = $constructor->construir($reporte);
            $personas = array_values(array_unique($contenido['personas']));

            /*
             * Carrera con BorrarSubject (hallazgo del code-review 2026-09-28):
             * 1) si alguien ya fue borrado desde que se leyo, se descarta;
             * 2) se registran las personas ANTES de escribir el archivo, para
             *    que un borrado posterior encuentre el reporte;
             * 3) se vuelve a verificar despues de escribir: si se borro a
             *    alguien en medio, se elimina el archivo.
             */
            if (! $this->siguenExistiendo($personas)) {
                $this->descartar($reporte, null);

                return;
            }
            $reporte->subjects()->sync($personas);

            $bytes = $reporte->formato === 'pdf'
                ? $pdf->desdeVista($contenido['vista'], $contenido['datos'], $contenido['orientacion'])
                : EscritorCsv::escribir($contenido['csv']['encabezados'], $contenido['csv']['filas']);

            $ruta = "tenants/{$this->tenantId}/reportes/reporte-{$reporte->id}.{$reporte->formato}";
            Report::disco()->put($ruta, $bytes);

            $reporte->forceFill(['estado' => 'listo', 'archivo_path' => $ruta, 'generado_en' => now()])->save();

            if (! $this->siguenExistiendo($personas)) {
                $this->descartar($reporte, $ruta);
            }
        });
    }

    /** @param  list<int>  $personas */
    private function siguenExistiendo(array $personas): bool
    {
        return Subject::query()->whereIn('id', $personas)->count() === count($personas);
    }

    private function descartar(Report $reporte, ?string $ruta): void
    {
        if ($ruta !== null) {
            Report::disco()->delete($ruta);
        }

        // Si BorrarSubject ya elimino la fila, esto no actualiza nada.
        Report::query()->whereKey($reporte->id)->update([
            'estado' => 'fallido',
            'archivo_path' => null,
            'error' => 'Una persona incluida se eliminó mientras se generaba el reporte. Vuelve a generarlo.',
        ]);
    }

    public function failed(Throwable $e): void
    {
        Log::error('No se pudo generar un reporte.', ['reporte_id' => $this->reporteId, 'error' => $e->getMessage()]);

        Tenant::find($this->tenantId)?->run(fn () => Report::query()->whereKey($this->reporteId)
            ->update(['estado' => 'fallido', 'error' => 'No se pudo generar el reporte. Intenta de nuevo.']));
    }
}
