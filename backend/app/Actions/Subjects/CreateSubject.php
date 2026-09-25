<?php

declare(strict_types=1);

namespace App\Actions\Subjects;

use App\Models\Subject;
use App\Services\Matching\IndiceSubjects;
use Illuminate\Support\Facades\DB;

/**
 * Alta en la lista de vigilancia, con aliases opcionales. Subject y
 * aliases se indexan UNA vez, ya completos (sin esto el observer de Scout
 * indexaba al crear el Subject, antes de que existieran sus aliases).
 */
class CreateSubject
{
    public function __construct(private readonly IndiceSubjects $indice) {}

    /**
     * @param  array<string, mixed>  $data  puede traer 'aliases' => list<string>
     */
    public function handle(array $data): Subject
    {
        $aliases = $data['aliases'] ?? [];
        unset($data['aliases']);

        return DB::transaction(function () use ($data, $aliases) {
            $subject = Subject::withoutSyncingToSearch(function () use ($data, $aliases) {
                $subject = Subject::create($data);

                foreach ($aliases as $nombre) {
                    $subject->aliases()->create(['nombre' => $nombre]);
                }

                return $subject;
            });

            $this->indice->reindexar($subject);

            return $subject;
        });
    }
}
