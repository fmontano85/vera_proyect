<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Subjects\ActualizarSubject;
use App\Actions\Subjects\CreateSubject;
use App\Actions\Subjects\IniciarConsultaPuntual;
use App\Actions\Subjects\ListarSubjects;
use App\Models\MentionMatch;
use App\Models\Subject;
use App\Services\Seguimiento\CalculadoraSeguimiento;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class SubjectController extends Controller
{
    /**
     * Lista de vigilancia: activos por defecto; filtros por estado y nivel;
     * busqueda por nombre canonico o alias (LIKE simple para la pantalla
     * de gestion - el matching de nombres usa Meilisearch, seccion 2).
     */
    public function index(Request $request, ListarSubjects $action, CalculadoraSeguimiento $calculadora): JsonResponse
    {
        $this->authorize('viewAny', Subject::class);

        $filtros = $request->validate([
            'buscar' => ['nullable', 'string', 'max:100'],
            'nivel' => ['nullable', 'in:alto,medio,bajo,sin_nivel'],
            'estado' => ['nullable', 'in:activos,inactivos,todos'],
        ]);

        $subjects = $action->handle($filtros);
        $frecuencias = $calculadora->frecuenciasDelTenant(tenant('id'));

        return response()->json($subjects->through(fn (Subject $subject) => $calculadora->serializar($subject, $frecuencias)));
    }

    public function store(Request $request, CreateSubject $action, CalculadoraSeguimiento $calculadora): JsonResponse
    {
        $this->authorize('create', Subject::class);

        $validated = $request->validate([
            'tipo' => ['required', 'in:natural,juridica'],
            'nombre_canonico' => ['required', 'string', 'max:255'],
            'documento' => ['nullable', 'string', 'max:255'],
            'nivel_riesgo' => ['nullable', 'in:bajo,medio,alto'],
            'aliases' => ['nullable', 'array', 'max:'.SubjectAliasController::MAX_ALIASES],
            'aliases.*' => [
                'required', 'string', 'max:255', 'distinct:ignore_case',
                SubjectAliasController::distintoDelNombreCanonico($request->input('nombre_canonico')),
            ],
        ]);

        $subject = $action->handle($validated);

        return response()->json($calculadora->serializar($subject->load('aliases')), 201);
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
            'nombre_canonico' => ['sometimes', 'required', 'string', 'max:255'],
            'tipo' => ['sometimes', 'required', 'in:natural,juridica'],
            'documento' => ['sometimes', 'nullable', 'string', 'max:255'],
            'nivel_riesgo' => ['sometimes', 'nullable', 'in:bajo,medio,alto'],
            'frecuencia_seguimiento_dias' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:365'],
            'activo' => ['sometimes', 'boolean'],
        ]);

        // Solo CAMBIAR el estado exige oficial/admin: un formulario que
        // reenvia el valor actual no debe bloquear al analista.
        if (array_key_exists('activo', $validated) && (bool) $validated['activo'] !== $subject->activo) {
            $this->authorize('cambiarEstado', $subject);
        }

        return response()->json($calculadora->serializar($action->handle($subject, $validated)->load('aliases')));
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
