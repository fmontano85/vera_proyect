<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Seccion 3.7 del CLAUDE.md raiz - "Captura manual (reglas confirmadas)".
 * Autorizacion vive en el controlador ($this->authorize()), no aqui -
 * mismo patron que el resto de controladores del proyecto.
 */
class CapturaManualRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'nombre_como_aparece' => ['required', 'string', 'max:255'],
            'rol' => ['required', 'in:imputado,condenado,victima,testigo,otro'],
            'delitos' => ['required', 'array', 'min:1'],
            'delitos.*' => ['required', 'string', 'max:255'],
            'fecha_hecho' => ['nullable', 'date'],
            'resumen' => ['nullable', 'string'],
            'estado_resolucion' => ['required', 'in:confirmado,falso_positivo,homonimo'],
            // Evidencia obligatoria (decision del usuario 2026-09-24): sin
            // esto un fetch fallido no deja ningun respaldo verificable.
            'pdf' => ['required', 'file', 'mimes:pdf', 'max:10240'],
        ];
    }
}
