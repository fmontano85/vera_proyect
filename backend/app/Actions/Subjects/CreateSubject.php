<?php

declare(strict_types=1);

namespace App\Actions\Subjects;

use App\Models\Subject;

class CreateSubject
{
    public function handle(array $data): Subject
    {
        return Subject::create($data);
    }
}
