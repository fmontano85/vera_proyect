<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Jobs\ImportSanctionListsJob;
use App\Jobs\MatchSanctionsJob;
use App\Models\ConfiguracionSanciones;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Stancl\Tenancy\Database\Models\Tenant;

/**
 * Primer panel de superadmin (seccion 3.2 del CLAUDE.md raiz, Fase 3 -
 * hoy solo esto: activar/desactivar Sanciones por tenant y el modo de
 * descarga global de la lista OFAC, decision del usuario 2026-09-28).
 * Fuera de las rutas de tenant: superadmin no tiene tenant_id.
 */
class SuperadminController extends Controller
{
    public function tenants(): JsonResponse
    {
        Gate::authorize('gestionar', Tenant::class);

        return response()->json(
            Tenant::query()->orderBy('created_at')->get()
                ->map(fn (Tenant $t) => $this->serializarTenant($t))
        );
    }

    public function actualizarTenant(Request $request, Tenant $tenant): JsonResponse
    {
        Gate::authorize('gestionar', Tenant::class);

        $validated = $request->validate([
            'sanciones_habilitado' => ['required', 'boolean'],
        ]);

        $anterior = (bool) $tenant->sanciones_habilitado;
        $tenant->update($validated);

        // Tenant es un modelo de stancl/tenancy: no tiene LogsActivity
        // (no es nuestro para agregarle el trait) - se registra a mano,
        // igual que ActualizarUsuario::handle() hace con 'rol_cambiado'.
        if ($anterior !== (bool) $tenant->sanciones_habilitado) {
            activity()->performedOn($tenant)->causedBy($request->user())->event('sanciones_cambiado')
                ->withProperties(['de' => $anterior, 'a' => (bool) $tenant->sanciones_habilitado])
                ->log('Sanciones (OFAC) '.($tenant->sanciones_habilitado ? 'habilitada' : 'deshabilitada').' para el tenant');
        }

        return response()->json($this->serializarTenant($tenant));
    }

    public function verConfiguracionSanciones(): JsonResponse
    {
        Gate::authorize('gestionar', Tenant::class);

        return response()->json($this->serializarConfiguracion(ConfiguracionSanciones::actual()));
    }

    public function actualizarConfiguracionSanciones(Request $request): JsonResponse
    {
        Gate::authorize('gestionar', Tenant::class);

        $validated = $request->validate([
            'modo_descarga_ofac' => ['required', Rule::in(['manual', 'automatico'])],
        ]);

        $config = ConfiguracionSanciones::actual();
        $config->update([...$validated, 'actualizado_por' => $request->user()->id]);

        return response()->json($this->serializarConfiguracion($config));
    }

    /**
     * Dispara la importacion + cruce a mano, sin esperar al domingo -
     * disponible en cualquier modo (manual o automatico): en modo manual
     * es la UNICA forma de actualizar la lista.
     */
    public function actualizarListaSanciones(): JsonResponse
    {
        Gate::authorize('gestionar', Tenant::class);

        Bus::chain([
            new ImportSanctionListsJob('ofac_sdn'),
            new MatchSanctionsJob,
        ])->dispatch();

        return response()->json(['mensaje' => 'Actualizacion de la lista OFAC encolada.']);
    }

    /** @return array{id: string, name: ?string, sanciones_habilitado: bool} */
    private function serializarTenant(Tenant $t): array
    {
        return [
            'id' => $t->id,
            'name' => $t->name,
            'sanciones_habilitado' => (bool) $t->sanciones_habilitado,
        ];
    }

    /** @return array{modo_descarga_ofac: string, actualizado_por: ?string, updated_at: ?string} */
    private function serializarConfiguracion(ConfiguracionSanciones $c): array
    {
        return [
            'modo_descarga_ofac' => $c->modo_descarga_ofac,
            'actualizado_por' => $c->actualizadoPor?->name,
            'updated_at' => $c->updated_at?->toIso8601String(),
        ];
    }
}
