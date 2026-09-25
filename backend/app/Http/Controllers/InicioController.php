<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\EstadoSearchResult;
use App\Models\MentionMatch;
use App\Models\SearchResult;
use App\Models\Subject;
use App\Services\Seguimiento\CalculadoraSeguimiento;
use Illuminate\Http\JsonResponse;

/**
 * Pagina de inicio (dashboard): contadores de lo que requiere accion en
 * el tenant, cada uno con su bandeja o panel en el frontend. Solo cuenta;
 * todo lo filtra el scope de tenancy.
 */
class InicioController extends Controller
{
    private const DIAS_PROXIMOS = 7;

    public function resumen(CalculadoraSeguimiento $calculadora): JsonResponse
    {
        $this->authorize('viewAny', Subject::class);

        $hoy = $calculadora->hoy();

        // Mismos scopes que las bandejas y el panel: los contadores no
        // pueden divergir de lo que muestra cada pantalla.
        return response()->json([
            'coincidencias_sin_propuesta' => MentionMatch::query()->enBandeja('sin_propuesta')->count(),
            'coincidencias_esperan_resolucion' => MentionMatch::query()->enBandeja('esperan_resolucion')->count(),
            'seguimientos_vencidos' => Subject::query()->vencidosAl($hoy->toDateString())->count(),
            'seguimientos_proximos_7_dias' => Subject::query()->proximosAl($hoy, self::DIAS_PROXIMOS)->count(),
            'resultados_gap' => SearchResult::query()->where('estado', EstadoSearchResult::Gap)->count(),
        ]);
    }
}
