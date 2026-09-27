<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Punto unico del chequeo de users.activo para cualquier ruta autenticada
 * (con o sin tenant). Antes de esto el chequeo se repetia por separado en
 * AuthController::login, AuthController::me e
 * InitializeTenancyFromAuthenticatedUser - y las rutas de cuenta propia
 * (fuera del grupo 'tenant': PATCH /cuenta, POST /cuenta/contrasena) no
 * tenian ningun chequeo explicito, solo dependian de que
 * ActualizarUsuario::cerrarSesiones ya hubiera borrado la sesion como
 * efecto secundario de desactivar al usuario.
 *
 * `=== false`, no `!`: un User resuelto por Sanctum siempre trae el
 * atributo cargado, pero el patron se mantiene igual al resto del codigo
 * (ver InitializeTenancyFromAuthenticatedUser) por si algun dia se
 * selecciona un subconjunto de columnas.
 */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_if($request->user()?->activo === false, 401);

        return $next($request);
    }
}
