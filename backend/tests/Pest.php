<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Stancl\Tenancy\Database\Models\Tenant;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class)->in('Feature');
uses(TestCase::class)->in('Unit');

/**
 * Usuario de un tenant con un rol, para cualquier Feature test. Antes
 * repetido con 7 nombres distintos (usuarioTenant, usuarioSanciones,
 * usuarioEvidencia, usuarioGestionTags, usuarioHistorial,
 * usuarioDashboardConRol, usuarioListaConRol) - un solo punto para el
 * dia en que User necesite un atributo mas o cambie el mecanismo de
 * asignacion de tenant.
 */
function usuarioDeTenant(Tenant $tenant, string $rol, array $atributos = []): User
{
    $user = User::factory()->create($atributos);
    $user->forceFill(['tenant_id' => $tenant->id])->save();
    $user->assignRole($rol);

    return $user;
}
