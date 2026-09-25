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

    /**
     * Seccion 3.8: editar nivel de riesgo / frecuencia de seguimiento del
     * subject - mismos roles que gestionan la lista de vigilancia.
     */
    public function update(User $user, Subject $subject): bool
    {
        return $user->hasAnyRole(['admin', 'oficial_cumplimiento', 'analista']);
    }

    /**
     * Activar/desactivar saca o devuelve a la persona del matching y de la
     * agenda de seguimiento: decision de cumplimiento, no operativa
     * (decision del usuario 2026-09-25, seccion 3.2: el oficial "gestiona
     * la lista de vigilancia"). analista no.
     */
    public function cambiarEstado(User $user, Subject $subject): bool
    {
        return $user->hasAnyRole(['admin', 'oficial_cumplimiento']);
    }

    /**
     * Seccion 3.8: cerrar un seguimiento. No es una resolucion de
     * coincidencia (no aplica el control de dos pasos de la 3.2), por eso
     * analista tambien puede. lectura no.
     */
    public function marcarSeguimiento(User $user, Subject $subject): bool
    {
        return $user->hasAnyRole(['admin', 'oficial_cumplimiento', 'analista']);
    }
}
