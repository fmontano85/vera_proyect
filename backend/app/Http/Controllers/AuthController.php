<?php

declare(strict_types=1);

namespace App\Http\Controllers;

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

        if (! Auth::attempt($credentials)) {
            // Mensaje generico a proposito (OWASP A07): no revelar si el
            // email existe o no.
            throw ValidationException::withMessages([
                'email' => ['Las credenciales no coinciden con nuestros registros.'],
            ]);
        }

        $request->session()->regenerate();

        return $this->conUsuarioYRoles($request);
    }

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

        return response()->json([
            ...$user->toArray(),
            'roles' => $user->getRoleNames(),
        ]);
    }

    public function logout(Request $request): Response
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->noContent();
    }
}
