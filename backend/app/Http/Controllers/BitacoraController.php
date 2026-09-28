<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Bitacora\ListarBitacora;
use App\Models\Activity;
use App\Services\Bitacora\SerializadorBitacora;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Stancl\Tenancy\Database\Models\Tenant;

/**
 * Bitacora (seccion 3.9, punto 7 - decision del usuario 2026-09-28): el
 * admin consulta la de su tenant completa; el superadmin la de todos los
 * tenants sin datos personales (opcion A).
 */
class BitacoraController extends Controller
{
    public function delTenant(Request $request, ListarBitacora $listar, SerializadorBitacora $serializador): JsonResponse
    {
        Gate::authorize('ver-bitacora-tenant');

        $filtros = $request->validate([
            'evento' => ['nullable', 'string', 'max:100'],
            'usuario_id' => ['nullable', 'integer'],
            'desde' => ['nullable', 'date_format:Y-m-d'],
            'hasta' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:desde'],
        ]);

        return response()->json(
            $listar->delTenant((string) tenant()->getTenantKey(), $filtros)
                ->through(fn (Activity $registro) => $serializador->completo($registro))
        );
    }

    /** Eventos que existen en la bitacora del tenant, para el filtro del frontend. */
    public function eventosDelTenant(): JsonResponse
    {
        Gate::authorize('ver-bitacora-tenant');

        return response()->json(
            Activity::query()->where('tenant_id', tenant()->getTenantKey())
                ->whereNotNull('event')->distinct()->orderBy('event')->pluck('event')
        );
    }

    public function global(Request $request, ListarBitacora $listar, SerializadorBitacora $serializador): JsonResponse
    {
        Gate::authorize('gestionar', Tenant::class);

        $filtros = $request->validate([
            'tenant_id' => ['nullable', 'string', 'max:36'],
            'evento' => ['nullable', 'string', 'max:100'],
            'desde' => ['nullable', 'date_format:Y-m-d'],
            'hasta' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:desde'],
        ]);

        $pagina = $listar->global($filtros);
        $nombres = Tenant::query()
            ->whereIn('id', $pagina->getCollection()->pluck('tenant_id')->filter()->unique())
            ->get()->mapWithKeys(fn (Tenant $t) => [$t->id => $t->name])->all();

        return response()->json(
            $pagina->through(fn (Activity $registro) => $serializador->sinDatosPersonales($registro, $nombres))
        );
    }

    public function eventosGlobal(): JsonResponse
    {
        Gate::authorize('gestionar', Tenant::class);

        return response()->json(
            Activity::query()->whereNotNull('event')->distinct()->orderBy('event')->pluck('event')
        );
    }
}
