<?php

declare(strict_types=1);

namespace App\Actions\Subjects;

use App\Models\MentionMatch;
use App\Models\Subject;
use App\Models\SubjectAlias;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Spatie\Activitylog\Models\Activity;

/**
 * Historial de auditoria de una persona vigilada (seccion 7 del CLAUDE.md
 * raiz): sus cambios, sus aliases (incluso los ya borrados), los
 * seguimientos y la resolucion de sus coincidencias.
 *
 * activity_log no tiene tenant_id: el aislamiento lo da el Subject ya
 * autorizado por el route-model-binding; todo lo que se consulta aqui
 * cuelga de ese subject_id.
 */
class ListarHistorialSubject
{
    public function handle(Subject $subject, int $porPagina = 20): LengthAwarePaginator
    {
        $matchIds = MentionMatch::query()->where('subject_id', $subject->id)->pluck('id');

        return Activity::query()
            ->with('causer:id,name')
            ->where(function (Builder $q) use ($subject, $matchIds) {
                $q->where(fn (Builder $s) => $s->where('subject_type', Subject::class)->where('subject_id', $subject->id))
                    ->orWhere(fn (Builder $s) => $s->where('subject_type', MentionMatch::class)->whereIn('subject_id', $matchIds))
                    // Un alias borrado ya no existe en subject_aliases: se
                    // identifica por el subject_id que quedo en el registro.
                    ->orWhere(fn (Builder $s) => $s->where('subject_type', SubjectAlias::class)
                        ->where(fn (Builder $j) => $j
                            ->where('attribute_changes->attributes->subject_id', $subject->id)
                            ->orWhere('attribute_changes->old->subject_id', $subject->id)));
            })
            ->latest('id')
            ->paginate($porPagina)
            ->through(fn (Activity $a) => [
                'id' => $a->id,
                'entidad' => match ($a->subject_type) {
                    SubjectAlias::class => 'alias',
                    MentionMatch::class => 'coincidencia',
                    default => 'subject',
                },
                'evento' => $a->event,
                'descripcion' => $a->description,
                'usuario' => $a->causer?->name,
                'cambios' => $a->attribute_changes,
                'propiedades' => $a->properties,
                'creado_en' => $a->created_at,
            ]);
    }
}
