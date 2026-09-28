<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\ProteccionDatos\DarDeBajaTenant;
use App\Actions\ProteccionDatos\ExportarTenant;
use App\Actions\Usuarios\CrearUsuario;
use App\Jobs\ImportSanctionListsJob;
use App\Jobs\MatchSanctionsJob;
use App\Models\ConfiguracionSanciones;
use App\Models\SanctionList;
use App\Services\ProteccionDatos\EstadoDocumentosLegales;
use App\Support\ConfiguracionTenant;
use App\Support\DescargaZip;
use App\Support\RegistroDeAccesos;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use App\Exceptions\OperacionNoPermitida;
use Stancl\Tenancy\Database\Models\Tenant;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Primer panel de superadmin (seccion 3.2 del CLAUDE.md raiz, Fase 3 -
 * hoy: alta/renombrado de tenants, activar/desactivar Sanciones por
 * tenant y el modo de descarga global de la lista OFAC). Fuera de las
 * rutas de tenant: superadmin no tiene tenant_id.
 */
class SuperadminController extends Controller
{
    public function __construct(private readonly EstadoDocumentosLegales $documentos) {}

    public function tenants(): JsonResponse
    {
        Gate::authorize('gestionar', Tenant::class);

        $tenants = Tenant::query()->orderBy('created_at')->get();
        $alDia = $this->documentos->alDiaPorTenant($tenants->pluck('id')->map(fn ($id) => (string) $id)->all());

        return response()->json(
            $tenants->map(fn (Tenant $t) => $this->serializarTenant($t, $alDia[(string) $t->id] ?? false))
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
            'depuracion_habilitada' => ['sometimes', 'required', 'boolean'],
        ]);

        abort_if($validated === [], 422, 'No hay nada que actualizar.');

        $nombreAnterior = $tenant->name;
        $sancionesAntes = (bool) $tenant->sanciones_habilitado;
        $depuracionAntes = ConfiguracionTenant::depuracionHabilitada($tenant);
        $tenant->update($validated);

        // Seccion 3.9, punto 3: la depuracion borra datos reales; queda constancia de quien la activo.
        if (array_key_exists('depuracion_habilitada', $validated) && $depuracionAntes !== (bool) $validated['depuracion_habilitada']) {
            activity()->performedOn($tenant)->causedBy($request->user())->event('depuracion_cambiada')
                ->withProperties(['de' => $depuracionAntes, 'a' => (bool) $validated['depuracion_habilitada']])
                ->log('Depuración por plazo de retención '.($validated['depuracion_habilitada'] ? 'habilitada' : 'deshabilitada'));
        }

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

    /** Seccion 3.9, punto 6: devolucion de datos al cliente antes de la baja. */
    public function exportarTenant(Request $request, Tenant $tenant, ExportarTenant $action): StreamedResponse
    {
        Gate::authorize('gestionar', Tenant::class);

        // Un tenant grande tarda: sin esto el limite de ejecucion corta la exportacion.
        set_time_limit(0);

        return DescargaZip::responder(
            $action->handle($tenant, $request->user()),
            'tenant-'.substr((string) $tenant->id, 0, 8).'.zip',
            // La baja exige este registro: solo cuenta si el cliente recibio el ZIP completo.
            fn () => RegistroDeAccesos::registrar('tenant_exportado', 'Exportación completa del tenant', $tenant),
        );
    }

    /**
     * Seccion 3.9, punto 6: baja definitiva. Se confirma escribiendo el
     * nombre del tenant (o su id si no tiene nombre) y exige una
     * exportacion reciente.
     */
    public function darDeBaja(Request $request, Tenant $tenant, DarDeBajaTenant $action): Response|JsonResponse
    {
        Gate::authorize('gestionar', Tenant::class);

        $request->validate(['confirmacion' => ['required', 'string', 'max:255']]);
        $esperado = $tenant->name ?? (string) $tenant->id;
        abort_unless(
            mb_strtolower(trim($request->input('confirmacion'))) === mb_strtolower(trim($esperado)),
            422,
            'El texto escrito no coincide con el nombre del tenant.',
        );

        try {
            $action->handle($tenant, $request->user());
        } catch (OperacionNoPermitida $e) {
            return response()->json(['mensaje' => $e->getMessage()], 422);
        }

        return response()->noContent();
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

    /** @return array<string, mixed> */
    private function serializarTenant(Tenant $t, ?bool $documentosAlDia = null): array
    {
        return [
            'id' => $t->id,
            'name' => $t->name,
            'sanciones_habilitado' => (bool) $t->sanciones_habilitado,
            // Seccion 3.9, punto 1: aceptó los terminos y el contrato vigentes.
            // El listado lo precalcula para todos (alDiaPorTenant) en una sola consulta.
            'documentos_al_dia' => $documentosAlDia ?? $this->documentos->alDia($t->id),
            // Seccion 3.9, puntos 2 y 3.
            'retencion_anios' => ConfiguracionTenant::retencionAnios($t),
            'depuracion_habilitada' => ConfiguracionTenant::depuracionHabilitada($t),
        ];
    }

    /**
     * Incluye el estado de la lista OFAC importada (null si nunca se
     * importo): el superadmin necesita ver si la lista esta al dia, no
     * solo el modo de descarga.
     *
     * @return array<string, mixed>
     */
    private function serializarConfiguracion(ConfiguracionSanciones $c): array
    {
        $lista = SanctionList::query()->where('codigo', 'ofac_sdn')->withCount('entries')->first();

        return [
            'modo_descarga_ofac' => $c->modo_descarga_ofac,
            'actualizado_por' => $c->actualizadoPor?->name,
            'updated_at' => $c->updated_at?->toIso8601String(),
            'lista' => $lista === null ? null : [
                'version' => $lista->version,
                'fecha_importacion' => $lista->fecha_importacion?->toIso8601String(),
                'entradas' => $lista->entries_count,
            ],
        ];
    }
}
