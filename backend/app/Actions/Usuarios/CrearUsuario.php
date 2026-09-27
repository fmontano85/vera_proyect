<?php

declare(strict_types=1);

namespace App\Actions\Usuarios;

use App\Models\User;

/**
 * Alta de un usuario en el tenant del admin que la ejecuta. El tenant sale
 * SIEMPRE del contexto (nunca del body) y el rol ya llega validado contra
 * ROLES_ASIGNABLES (superadmin no es asignable).
 */
class CrearUsuario
{
    public function handle(string $tenantId, string $name, string $email, string $password, string $rol): User
    {
        $usuario = new User(['name' => $name, 'email' => $email, 'password' => $password]);
        $usuario->forceFill(['tenant_id' => $tenantId, 'activo' => true])->save();
        $usuario->assignRole($rol);

        return $usuario;
    }
}
