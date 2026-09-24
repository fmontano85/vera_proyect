<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\SearchResult;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Seccion 3.7 del CLAUDE.md raiz - "Captura manual (reglas confirmadas)".
 * Autorizacion vive en el controlador ($this->authorize()), no aqui -
 * mismo patron que el resto de controladores del proyecto.
 *
 * subject_id (busqueda por tags, sesion posterior a la 3.7): solo se pide
 * cuando el resultado no tiene subject propio (route-model-binding ya
 * resuelve {resultado} antes de que esta clase se construya, asi que
 * podemos leerlo aqui para decidir la regla).
 */
class CapturaManualRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        /** @var SearchResult $resultado */
        $resultado = $this->route('resultado');

        return [
            'nombre_como_aparece' => ['required', 'string', 'max:255'],
            'rol' => ['required', 'in:imputado,condenado,victima,testigo,otro'],
            'delitos' => ['required', 'array', 'min:1'],
            'delitos.*' => ['required', 'string', 'max:255'],
            'fecha_hecho' => ['nullable', 'date'],
            'resumen' => ['nullable', 'string'],
            'estado_resolucion' => ['required', 'in:confirmado,falso_positivo,homonimo'],
            'subject_id' => [
                Rule::requiredIf($resultado?->subject_id === null),
                'integer',
                Rule::exists('subjects', 'id')->where('tenant_id', tenant('id')),
            ],
            // Evidencia obligatoria (decision del usuario 2026-09-24): sin
            // esto un fetch fallido no deja ningun respaldo verificable.
            'pdf' => ['required', 'file', 'mimes:pdf', 'max:10240'],
        ];
    }
}
