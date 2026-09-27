<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\SearchTag;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Catalogo de tags de busqueda por tenant (sesion posterior a la 3.7).
 * Delgado a proposito - sin Action dedicada porque la logica es un simple
 * create con validacion de unicidad por tenant.
 */
class SearchTagController extends Controller
{
    /** ?todos=1 incluye los inactivos y es solo para la pantalla de gestion. */
    public function index(Request $request): JsonResponse
    {
        $todos = $request->boolean('todos');
        $this->authorize($todos ? 'gestionar' : 'viewAny', SearchTag::class);

        return response()->json(
            SearchTag::query()
                ->when(! $todos, fn ($q) => $q->where('activo', true))
                ->orderBy('nombre')
                ->get()
        );
    }

    public function update(Request $request, SearchTag $tag): JsonResponse
    {
        $this->authorize('gestionar', SearchTag::class);

        $validated = $request->validate([
            'nombre' => [
                'sometimes',
                'required',
                'string',
                'max:255',
                Rule::unique('search_tags', 'nombre')->where('tenant_id', tenant('id'))->ignore($tag->id),
            ],
            'activo' => ['sometimes', 'required', 'boolean'],
        ]);

        abort_if($validated === [], 422, 'Indica el nombre o el estado del tag.');

        $tag->update($validated);

        return response()->json($tag);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', SearchTag::class);

        $validated = $request->validate([
            'nombre' => [
                'required',
                'string',
                'max:255',
                Rule::unique('search_tags', 'nombre')->where('tenant_id', tenant('id')),
            ],
        ]);

        $tag = SearchTag::create(['nombre' => $validated['nombre'], 'activo' => true]);

        return response()->json($tag, 201);
    }
}
