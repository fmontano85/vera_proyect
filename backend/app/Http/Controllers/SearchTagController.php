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
    public function index(): JsonResponse
    {
        $this->authorize('viewAny', SearchTag::class);

        return response()->json(
            SearchTag::query()->where('activo', true)->orderBy('nombre')->get()
        );
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
