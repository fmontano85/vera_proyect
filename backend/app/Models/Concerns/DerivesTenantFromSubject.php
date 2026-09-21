<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\Subject;

/**
 * Para modelos que ya usan Stancl\Tenancy\Database\Concerns\BelongsToTenant
 * pero ademas cuelgan de un Subject (subject_id): BelongsToTenant asigna
 * tenant_id desde el tenant AMBIENTE en tenancy(), no desde el subject
 * relacionado. Este trait lo sobreescribe siempre con el tenant_id real
 * del subject.
 *
 * A proposito NO usa Subject::withoutTenancy(): la consulta respeta el
 * scope de tenant activo, asi que un subject_id de OTRO tenant no se
 * encuentra (falla en vez de resolverse igual) - PERO esto solo protege
 * mientras tenancy() ya este inicializada (ej. dentro del middleware
 * 'tenant', o dentro de tenancy()->runForMultiple()). Fuera de un
 * contexto HTTP normal (un Job, un comando artisan, tinker, un seeder)
 * que no haya inicializado tenancy(), el scope de Subject no filtra nada
 * y esto falla abierto igual que BelongsToTenant (ver seccion 6 del
 * CLAUDE.md raiz: todo ese codigo debe inicializar tenancy() el mismo).
 *
 * Tambien solo se engancha en 'creating': si subject_id de un registro ya
 * existente cambiara via update(), tenant_id no se re-deriva. Hoy ningun
 * codigo hace eso, asi que no se protege contra un caso que no existe.
 */
trait DerivesTenantFromSubject
{
    protected static function bootDerivesTenantFromSubject(): void
    {
        static::creating(function (self $model) {
            $model->tenant_id = Subject::findOrFail($model->subject_id)->tenant_id;
        });
    }
}
