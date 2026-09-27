<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Usuarios\ActualizarUsuario;
use App\Actions\Usuarios\CrearUsuario;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Usuarios del tenant (solo admin). La contrasena inicial la fija el admin
 * y se la entrega a la persona; el envio por correo queda para cuando haya
 * SMTP real. User no tiene scope de tenant: TODA consulta filtra por
 * tenant_id aqui de forma explicita.
 */
class UsuarioController extends Controller
{
    /** superadmin nunca es asignable desde un tenant (OWASP A01). */
    public const ROLES_ASIGNABLES = ['admin', 'oficial_cumplimiento', 'analista', 'lectura'];

    public function index(): JsonResponse
    {
        $this->autorizar();

        return response()->json(
            User::query()
                ->where('tenant_id', tenant('id'))
                ->with('roles:id,name')
                ->orderBy('name')
                ->get()
                ->map(fn (User $u) => $this->serializar($u))
        );
    }

    public function store(Request $request, CrearUsuario $action): JsonResponse
    {
        $this->autorizar();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'string', self::reglaClave()],
            'rol' => ['required', Rule::in(self::ROLES_ASIGNABLES)],
        ]);

        $usuario = $action->handle(tenant('id'), $validated['name'], $validated['email'], $validated['password'], $validated['rol']);

        return response()->json($this->serializar($usuario->load('roles:id,name')), 201);
    }

    public function update(Request $request, User $usuario, ActualizarUsuario $action): JsonResponse
    {
        $this->autorizar();

        // Otro tenant o superadmin: 404, no 403 (no confirmar que existe).
        abort_if($usuario->tenant_id !== tenant('id') || $usuario->hasRole('superadmin'), 404);

        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'rol' => ['sometimes', 'required', Rule::in(self::ROLES_ASIGNABLES)],
            'activo' => ['sometimes', 'required', 'boolean'],
            'password' => ['sometimes', 'required', 'string', self::reglaClave()],
        ]);

        abort_if($validated === [], 422, 'No hay nada que actualizar.');

        // Sin esto un admin podria quedarse sin acceso a si mismo.
        if ($usuario->is($request->user())) {
            foreach (['rol', 'activo'] as $campo) {
                abort_if(array_key_exists($campo, $validated), 422, 'No puedes cambiar tu propio rol ni desactivarte.');
            }
        }

        // Al editarse a si mismo (ej. restablecer su propia contrasena),
        // la sesion que hace el request no debe cerrarse - ver
        // ActualizarUsuario::handle.
        $exceptoSesion = $usuario->is($request->user()) && $request->hasSession()
            ? $request->session()->getId()
            : null;

        return response()->json($this->serializar(
            $action->handle($usuario, $request->user(), $validated, $exceptoSesion)->load('roles:id,name')
        ));
    }

    public static function reglaClave(): Password
    {
        return Password::min(12)->letters()->numbers();
    }

    private function autorizar(): void
    {
        Gate::forUser(request()->user())->authorize('gestionar', [User::class]);
    }

    /** @return array{id: int, name: string, email: string, rol: ?string, activo: bool} */
    private function serializar(User $u): array
    {
        return [
            'id' => $u->id,
            'name' => $u->name,
            'email' => $u->email,
            'rol' => $u->roles->first()?->name,
            'activo' => (bool) $u->activo,
        ];
    }
}
