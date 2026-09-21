<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

/**
 * Roles de la seccion 3.2 del CLAUDE.md raiz. Son globales (no por
 * tenant): que rol tiene un usuario ya esta acotado por su propio
 * tenant_id (un usuario pertenece a un solo tenant), asi que no hace
 * falta la feature de "teams" de spatie/laravel-permission para esto.
 */
class RoleSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['superadmin', 'admin', 'oficial_cumplimiento', 'analista', 'lectura'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }
    }
}
