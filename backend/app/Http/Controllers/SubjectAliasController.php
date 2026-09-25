<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Subjects\GestionarAliases;
use App\Models\Subject;
use App\Models\SubjectAlias;
use App\Services\Seguimiento\CalculadoraSeguimiento;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Aliases de un subject de la lista de vigilancia. Mismos roles que
 * editar el subject (SubjectPolicy::update). La ruta usa scopeBindings():
 * el alias debe pertenecer al subject de la URL (si no, 404).
 */
class SubjectAliasController extends Controller
{
    /**
     * Mismo tope que el alta: pasado ese numero, LimiteDeQuery empezaria a
     * omitir aliases de la query de Brave (75 palabras) en silencio.
     */
    public const MAX_ALIASES = 20;

    public function store(Request $request, Subject $subject, GestionarAliases $action, CalculadoraSeguimiento $calculadora): JsonResponse
    {
        $this->authorize('update', $subject);

        $validated = $request->validate([
            'nombre' => [
                'required', 'string', 'max:255',
                Rule::unique('subject_aliases', 'nombre')->where('subject_id', $subject->id),
                self::distintoDelNombreCanonico($subject->nombre_canonico),
                function (string $atributo, mixed $valor, Closure $falla) use ($subject) {
                    if ($subject->aliases()->count() >= self::MAX_ALIASES) {
                        $falla('Este sujeto ya tiene el máximo de '.self::MAX_ALIASES.' aliases.');
                    }
                },
            ],
        ]);

        try {
            $subject = $action->agregar($subject, trim($validated['nombre']));
        } catch (UniqueConstraintViolationException) {
            // Dos envios simultaneos del mismo alias: ambos pasan la
            // validacion; el unique (subject_id, nombre) frena al segundo.
            throw ValidationException::withMessages(['nombre' => 'Ese alias ya existe para este sujeto.']);
        }

        return response()->json($calculadora->serializar($subject->load('aliases')), 201);
    }

    public function destroy(Subject $subject, SubjectAlias $alias, GestionarAliases $action, CalculadoraSeguimiento $calculadora): JsonResponse
    {
        $this->authorize('update', $subject);

        $subject = $action->quitar($subject, $alias);

        return response()->json($calculadora->serializar($subject->load('aliases')));
    }

    /**
     * Un alias igual al nombre canonico no agrega cobertura y gasta
     * palabras de la query de Brave. Sin distinguir mayusculas.
     */
    public static function distintoDelNombreCanonico(?string $nombreCanonico): Closure
    {
        return function (string $atributo, mixed $valor, Closure $falla) use ($nombreCanonico) {
            if ($nombreCanonico !== null && mb_strtolower(trim((string) $valor)) === mb_strtolower(trim($nombreCanonico))) {
                $falla('El alias no puede ser igual al nombre completo.');
            }
        };
    }
}
