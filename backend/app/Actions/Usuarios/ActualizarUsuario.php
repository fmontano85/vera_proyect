<?php

declare(strict_types=1);

namespace App\Actions\Usuarios;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class ActualizarUsuario
{
    /**
     * @param  array{name?: string, rol?: string, activo?: bool, password?: string}  $datos
     *
     * $exceptoSesion: cuando el admin se edita a si mismo, la sesion que
     * hace el request no debe cerrarse (mismo criterio que
     * CuentaController::cambiarContrasena) - sin esto, un admin que se
     * restablece su propia contrasena desde /usuarios queda deslogueado
     * de inmediato por su propio cambio.
     */
    public function handle(User $usuario, User $admin, array $datos, ?string $exceptoSesion = null): User
    {
        return DB::transaction(function () use ($usuario, $admin, $datos, $exceptoSesion) {
            if (isset($datos['name'])) {
                $usuario->name = $datos['name'];
            }
            if (isset($datos['activo'])) {
                $usuario->activo = $datos['activo'];
            }
            $usuario->save();

            if (isset($datos['rol']) && ! $usuario->hasRole($datos['rol'])) {
                $anterior = $usuario->getRoleNames()->first();
                $usuario->syncRoles([$datos['rol']]);
                activity()->performedOn($usuario)->causedBy($admin)->event('rol_cambiado')
                    ->withProperties(['de' => $anterior, 'a' => $datos['rol']])->log('Rol cambiado');
            }

            if (isset($datos['password'])) {
                $usuario->forceFill(['password' => $datos['password']])->save();
                activity()->performedOn($usuario)->causedBy($admin)->event('contrasena_restablecida')
                    ->log('Contraseña restablecida por un administrador');
            }

            // Desactivar o cambiar la clave invalida lo que ya estaba abierto.
            if (($datos['activo'] ?? true) === false || isset($datos['password'])) {
                self::cerrarSesiones($usuario, $exceptoSesion);
            }

            return $usuario->refresh();
        });
    }

    public static function cerrarSesiones(User $usuario, ?string $exceptoSesion = null): void
    {
        DB::table('sessions')
            ->where('user_id', $usuario->id)
            ->when($exceptoSesion, fn ($q) => $q->where('id', '!=', $exceptoSesion))
            ->delete();
        $usuario->tokens()->delete();
    }
}
