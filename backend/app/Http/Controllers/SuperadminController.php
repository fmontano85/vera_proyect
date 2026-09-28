<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Usuarios\CrearUsuario;
use App\Jobs\ImportSanctionListsJob;
use App\Jobs\MatchSanctionsJob;
use App\Models\ConfiguracionSanciones;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Stancl\Tenancy\Database\Models\Tenant;

/**
 * Primer panel de superadmin (seccion 3.2 del CLAUDE.md raiz, Fase 3 -
 * hoy: alta/renombrado de tenants, activar/desactivar Sanciones por
 * tenant y el modo de descarga global de la lista OFAC). Fuera de las
 * rutas de tenant: superadmin no tiene tenant_id.
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

    /**
     * Crea el tenant y su primer usuario admin en una sola operacion - un
     * tenant sin ningun usuario es inutil (nadie puede entrar a el, y
     * POST /api/usuarios exige ya estar autenticado DENTRO de un tenant,
     * asi que no hay forma circular de resolverlo despues).
     */
    public function crearTenant(Request $request, CrearUsuario $accionUsuario): JsonResponse
    {
        Gate::authorize('gestionar', Tenant::class);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'admin_name' => ['required', 'string', 'max:255'],
            'admin_email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'admin_password' => ['required', 'string', UsuarioController::reglaClave()],
        ]);

        [$tenant, $admin] = DB::transaction(function () use ($validated, $accionUsuario) {
            $tenant = Tenant::create(['name' => $validated['name']]);
            $admin = $accionUsuario->handle(
                $tenant->id,
                $validated['admin_name'],
                $validated['admin_email'],
                $validated['admin_password'],
                'admin',
            );

            return [$tenant, $admin];
        });

        activity()->performedOn($tenant)->causedBy($request->user())->event('tenant_creado')
            ->withProperties(['admin_email' => $admin->email])->log('Tenant creado');

        return response()->json([
            'tenant' => $this->serializarTenant($tenant),
            'admin' => ['name' => $admin->name, 'email' => $admin->email],
        ], 201);
    }

    public function actualizarTenant(Request $request, Tenant $tenant): JsonResponse
    {
        Gate::authorize('gestionar', Tenant::class);

        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'sanciones_habilitado' => ['sometimes', 'required', 'boolean'],
        ]);

        abort_if($validated === [], 422, 'No hay nada que actualizar.');

        $nombreAnterior = $tenant->name;
        $sancionesAntes = (bool) $tenant->sanciones_habilitado;
        $tenant->update($validated);

        // Tenant es un modelo de stancl/tenancy: no tiene LogsActivity (no
        // es nuestro para agregarle el trait) - se registra a mano, igual
        // que ActualizarUsuario::handle() hace con 'rol_cambiado'.
        if (array_key_exists('name', $validated) && $nombreAnterior !== $tenant->name) {
            activity()->performedOn($tenant)->causedBy($request->user())->event('tenant_renombrado')
                ->withProperties(['de' => $nombreAnterior, 'a' => $tenant->name])->log('Tenant renombrado');
        }

        if (array_key_exists('sanciones_habilitado', $validated) && $sancionesAntes !== (bool) $tenant->sanciones_habilitado) {
            activity()->performedOn($tenant)->causedBy($request->user())->event('sanciones_cambiado')
                ->withProperties(['de' => $sancionesAntes, 'a' => (bool) $tenant->sanciones_habilitado])
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
