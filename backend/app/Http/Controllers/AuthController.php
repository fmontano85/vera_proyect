<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\ProteccionDatos\EstadoDocumentosLegales;
use App\Support\RegistroDeAccesos;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Login/logout de Sanctum SPA por cookies (seccion 2 del CLAUDE.md raiz).
 * No existia hasta ahora - todo el testing previo de la sesion usaba
 * tokens creados por tinker/vera:demo, nunca un login real. El frontend
 * primero pide GET /sanctum/csrf-cookie (ruta que Sanctum registra solo),
 * despues POST aqui.
 */
class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        // 'activo' en las credenciales: un usuario desactivado falla igual que
        // uno con clave incorrecta (mismo mensaje generico).
        if (! Auth::guard('web')->attempt([...$credentials, 'activo' => true])) {
            // Mensaje generico a proposito (OWASP A07): no revelar si el
            // email existe o no.
            throw ValidationException::withMessages([
                'email' => ['Las credenciales no coinciden con nuestros registros.'],
            ]);
        }

        $request->session()->regenerate();

        RegistroDeAccesos::registrar('inicio_sesion', 'Inicio de sesión', $request->user());

        return $this->conUsuarioYRoles($request);
    }

    /**
     * El chequeo de users.activo ya lo hace el middleware 'activo' de la
     * ruta (App\Http\Middleware\EnsureUserIsActive) - no repetirlo aqui.
     */
    public function me(Request $request): JsonResponse
    {
        return $this->conUsuarioYRoles($request);
    }

    /**
     * El frontend necesita los roles para mostrar/ocultar acciones (ej.
     * "resolver" solo para oficial_cumplimiento/admin, seccion 3.2) - el
     * modelo User no los serializa por si solo (spatie/laravel-permission
     * los maneja por tabla pivote, no como columna).
     */
    private function conUsuarioYRoles(Request $request): JsonResponse
    {
        $user = $request->user();

        // tenant() no esta inicializado en esta ruta (fuera del grupo
        // 'tenant' - superadmin no tiene tenant_id): se lee directo de la
        // relacion, no del helper. Para que el frontend oculte "Sanciones"
        // sin tener que golpear el endpoint y recibir 404.
        return response()->json([
            ...$user->toArray(),
            'roles' => $user->getRoleNames(),
            'sanciones_habilitado' => (bool) ($user->tenant?->sanciones_habilitado ?? false),
            // Seccion 3.9, punto 1: el frontend avisa (y el backend bloquea crear/buscar).
            'terminos_pendientes' => $user->tenant_id !== null && ! app(EstadoDocumentosLegales::class)->alDia($user->tenant_id),
        ]);
    }

    public function logout(Request $request): Response
    {
        // Antes del logout: despues ya no hay causante.
        RegistroDeAccesos::registrar('cierre_sesion', 'Cierre de sesión', $request->user());

        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->noContent();
    }
}
