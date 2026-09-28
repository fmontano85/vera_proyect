<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Jobs\GenerarReporteJob;
use App\Models\Report;
use App\Models\Subject;
use App\Support\DescargaSegura;
use App\Support\RegistroDeAccesos;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Reportes de auditoria (seccion 1, punto 5). Todos los roles del tenant
 * los generan y descargan (seccion 3.2: lectura = "consulta y reportes");
 * el superadmin no entra a rutas de tenant. Se generan en segundo plano.
 */
class ReporteController extends Controller
{
    /** Rango maximo del reporte de actividad: acota el tamaño del reporte. */
    private const DIAS_MAXIMOS_PERIODO = 366;

    public function index(): JsonResponse
    {
        return response()->json(
            Report::query()->with('generadoPor')->latest('id')->paginate(20)->through(fn (Report $r) => $this->serializar($r))
        );
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'tipo' => ['required', Rule::in(Report::TIPOS)],
            'formato' => ['required', Rule::in(Report::FORMATOS)],
            'subject_id' => ['required_if:tipo,ficha_persona', 'nullable', 'integer'],
            'desde' => ['required_if:tipo,actividad_periodo', 'nullable', 'date_format:Y-m-d'],
            'hasta' => ['required_if:tipo,actividad_periodo', 'nullable', 'date_format:Y-m-d', 'after_or_equal:desde'],
            'nivel_riesgo' => ['required_if:tipo,lista_por_riesgo', 'nullable', Rule::in(['alto', 'medio', 'bajo', 'sin_nivel', 'todos'])],
            'incluir_inactivos' => ['nullable', 'boolean'],
        ]);

        $parametros = match ($validated['tipo']) {
            'ficha_persona' => $this->persona((int) $validated['subject_id']),
            'actividad_periodo' => $this->periodo($validated['desde'], $validated['hasta']),
            'lista_por_riesgo' => ['nivel_riesgo' => $validated['nivel_riesgo'], 'incluir_inactivos' => (bool) ($validated['incluir_inactivos'] ?? false)],
        };

        $reporte = Report::create([
            'tipo' => $validated['tipo'],
            'formato' => $validated['formato'],
            'parametros' => $parametros,
            'generado_por' => $request->user()->id,
        ]);
        // La ficha ya sabe a quien incluye: si la persona se borra mientras el
        // reporte esta en cola o fallo, BorrarSubject tambien lo encuentra.
        if ($reporte->tipo === 'ficha_persona') {
            $reporte->forceFill(['personas' => [$parametros['subject_id']]])->save();
        }

        RegistroDeAccesos::registrar('reporte_solicitado', 'Reporte solicitado', $reporte, ['tipo' => $reporte->tipo, 'formato' => $reporte->formato]);
        GenerarReporteJob::dispatch($reporte->id, (string) tenant()->getTenantKey());

        return response()->json($this->serializar($reporte->load('generadoPor')), 202);
    }

    public function descargar(Report $reporte): StreamedResponse
    {
        abort_unless($reporte->estado === 'listo' && $reporte->archivo_path !== null, 409, 'El reporte todavía no está listo.');
        abort_unless(Report::disco()->exists($reporte->archivo_path), 404, 'El archivo del reporte ya no está disponible.');

        RegistroDeAccesos::registrar('reporte_descargado', 'Reporte descargado', $reporte, ['tipo' => $reporte->tipo]);

        return DescargaSegura::propia(
            Report::disco(),
            $reporte->archivo_path,
            "reporte-{$reporte->id}-{$reporte->tipo}.{$reporte->formato}",
            $reporte->formato === 'pdf' ? 'application/pdf' : 'text/csv; charset=UTF-8',
        );
    }

    /**
     * La persona debe ser del tenant (scope): si no, 422 como cualquier dato
     * invalido. El nombre se guarda para mostrar la lista de reportes; se
     * borra junto con el reporte si la persona se elimina.
     *
     * @return array{subject_id: int, nombre: string}
     */
    private function persona(int $id): array
    {
        $subject = Subject::query()->find($id);
        abort_if($subject === null, 422, 'La persona indicada no existe en tu lista de vigilancia.');

        return ['subject_id' => $subject->id, 'nombre' => $subject->nombre_canonico];
    }

    /** @return array{desde: string, hasta: string} */
    private function periodo(string $desde, string $hasta): array
    {
        $dias = (new \DateTimeImmutable($desde))->diff(new \DateTimeImmutable($hasta))->days;
        abort_if($dias > self::DIAS_MAXIMOS_PERIODO, 422, 'El periodo no puede superar un año.');

        return ['desde' => $desde, 'hasta' => $hasta];
    }

    /** @return array<string, mixed> */
    private function serializar(Report $r): array
    {
        return [
            'id' => $r->id,
            'tipo' => $r->tipo,
            'formato' => $r->formato,
            'parametros' => $r->parametros,
            'estado' => $r->estado,
            'error' => $r->error,
            'generado_por' => $r->generadoPor?->name,
            'solicitado_en' => $r->created_at?->toIso8601String(),
            'generado_en' => $r->generado_en?->toIso8601String(),
        ];
    }
}
