<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Subjects\ActualizarSubject;
use App\Actions\Subjects\CreateSubject;
use App\Actions\Subjects\IniciarConsultaPuntual;
use App\Models\MentionMatch;
use App\Models\Subject;
use App\Services\Seguimiento\CalculadoraSeguimiento;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class SubjectController extends Controller
{
    public function index(): JsonResponse
    {
        $this->authorize('viewAny', Subject::class);

        return response()->json(Subject::query()->paginate());
    }

    public function store(Request $request, CreateSubject $action): JsonResponse
    {
        $this->authorize('create', Subject::class);

        $validated = $request->validate([
            'tipo' => ['required', 'in:natural,juridica'],
            'nombre_canonico' => ['required', 'string', 'max:255'],
            'documento' => ['nullable', 'string', 'max:255'],
            'nivel_riesgo' => ['nullable', 'in:bajo,medio,alto'],
        ]);

        return response()->json($action->handle($validated), 201);
    }

    public function show(Subject $subject, CalculadoraSeguimiento $calculadora): JsonResponse
    {
        $this->authorize('view', $subject);

        return response()->json($calculadora->serializar($subject->load('aliases')));
    }

    /**
     * Seccion 3.8: solo nivel de riesgo y frecuencia de seguimiento
     * personalizada (null = volver al default del nivel). Rango 1..365
     * sin piso regulatorio por ahora (decision del usuario 2026-09-25,
     * pendiente verificar el instructivo UIF).
     */
    public function update(Request $request, Subject $subject, ActualizarSubject $action, CalculadoraSeguimiento $calculadora): JsonResponse
    {
        $this->authorize('update', $subject);

        $validated = $request->validate([
            'nivel_riesgo' => ['sometimes', 'nullable', 'in:bajo,medio,alto'],
            'frecuencia_seguimiento_dias' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:365'],
        ]);

        return response()->json($calculadora->serializar($action->handle($subject, $validated)));
    }

    /**
     * Consulta puntual (seccion 1.1/5 del CLAUDE.md raiz): encola el
     * pipeline completo (seccion 3.4) para este subject. Responde 202
     * porque el resultado no esta listo todavia - el analista revisa
     * "matches" (estado pendiente) cuando el pipeline asincrono termine.
     */
    public function buscar(Subject $subject, IniciarConsultaPuntual $action): JsonResponse
    {
        $this->authorize('buscar', $subject);

        try {
            $fuentes = $action->handle($subject);
        } catch (RuntimeException $e) {
            return response()->json(['mensaje' => $e->getMessage()], 422);
        }

        return response()->json([
            'mensaje' => 'Consulta puntual encolada.',
            'subject_id' => $subject->id,
            'fuentes' => $fuentes,
        ], 202);
    }

    /**
     * Coincidencias propuestas por el pipeline (seccion 1, "el sistema
     * propone, el analista resuelve") para este subject, mas recientes
     * primero. Mismo permiso que ver el subject: "lectura" tambien puede
     * consultar (seccion 3.2).
     */
    public function matches(Subject $subject): JsonResponse
    {
        $this->authorize('view', $subject);

        $matches = MentionMatch::query()
            ->where('subject_id', $subject->id)
            ->with('mention.article')
            ->latest()
            ->paginate();

        return response()->json($matches);
    }
}
