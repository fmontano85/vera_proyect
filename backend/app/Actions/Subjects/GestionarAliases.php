<?php

declare(strict_types=1);

namespace App\Actions\Subjects;

use App\Models\Subject;
use App\Models\SubjectAlias;
use App\Services\Matching\IndiceSubjects;
use Illuminate\Support\Facades\DB;

/**
 * Aliases de un subject (seccion 3.3). Auditados por LogsActivity de
 * SubjectAlias (seccion 7). Cada cambio reindexa al subject dentro de la
 * transaccion: un alias que no esta en Meilisearch nunca generaria
 * coincidencias.
 */
class GestionarAliases
{
    public function __construct(private readonly IndiceSubjects $indice) {}

    public function agregar(Subject $subject, string $nombre): Subject
    {
        return DB::transaction(function () use ($subject, $nombre) {
            $subject->aliases()->create(['nombre' => $nombre]);
            $this->indice->reindexar($subject);

            return $subject;
        });
    }

    public function quitar(Subject $subject, SubjectAlias $alias): Subject
    {
        return DB::transaction(function () use ($subject, $alias) {
            $alias->delete();
            $this->indice->reindexar($subject);

            return $subject;
        });
    }
}
