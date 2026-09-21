<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Subject;
use App\Models\User;

/**
 * Roles y permisos por accion (seccion 3.2 del CLAUDE.md raiz):
 * - lectura: "solo consulta y reportes" -> puede ver, no crear.
 * - analista/oficial_cumplimiento/admin: pueden crear/gestionar subjects
 *   ("ejecuta consultas" / "gestiona lista de vigilancia").
 * view/viewAny no filtran por tenant aqui: eso ya lo hace el global scope
 * de tenant_id (BelongsToTenant) sobre el propio modelo Subject.
 */
class SubjectPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Subject $subject): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'oficial_cumplimiento', 'analista']);
    }

    /**
     * Ejecutar una consulta puntual gasta cuota de Google CSE y tokens de
     * Anthropic (seccion 3.4/9 del CLAUDE.md raiz) - mismo set de roles que
     * create(): "analista ejecuta consultas" (seccion 3.2), lectura no.
     */
    public function buscar(User $user, Subject $subject): bool
    {
        return $user->hasAnyRole(['admin', 'oficial_cumplimiento', 'analista']);
    }
}
