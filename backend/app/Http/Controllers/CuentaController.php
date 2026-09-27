<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Usuarios\ActualizarUsuario;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/** Cuenta propia de cualquier usuario autenticado (fuera de las rutas de tenant). */
class CuentaController extends Controller
{
    public function actualizar(Request $request): JsonResponse
    {
        // Solo el nombre: email, rol y tenant no los cambia uno mismo.
        $validated = $request->validate(['name' => ['required', 'string', 'max:255']]);

        $request->user()->update($validated);

        return response()->json(['name' => $request->user()->name]);
    }

    public function cambiarContrasena(Request $request): Response
    {
        $validated = $request->validate([
            'contrasena_actual' => ['required', 'string'],
            'contrasena' => ['required', 'string', 'confirmed', 'different:contrasena_actual', UsuarioController::reglaClave()],
        ]);

        $usuario = $request->user();

        // Hash::check y no la regla current_password: tras auth:sanctum el
        // guard por defecto ya no es 'web' (ver CLAUDE.md, patron auth+guards).
        if (! Hash::check($validated['contrasena_actual'], $usuario->password)) {
            throw ValidationException::withMessages(['contrasena_actual' => ['La contraseña actual no es correcta.']]);
        }

        $usuario->forceFill(['password' => $validated['contrasena']])->save();
        ActualizarUsuario::cerrarSesiones($usuario, $request->hasSession() ? $request->session()->getId() : null);

        activity()->performedOn($usuario)->causedBy($usuario)->event('contrasena_cambiada')->log('Contraseña cambiada por el propio usuario');

        return response()->noContent();
    }
}
