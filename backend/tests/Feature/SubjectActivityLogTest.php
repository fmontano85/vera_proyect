<?php

declare(strict_types=1);

use App\Models\Subject;
use Spatie\Activitylog\Models\Activity;
use Stancl\Tenancy\Database\Models\Tenant;

it('registra en activity_log la creacion de un subject', function () {
    $tenant = Tenant::create();
    tenancy()->initialize($tenant);

    $subject = Subject::factory()->create(['nombre_canonico' => 'Juan Perez']);

    $actividad = Activity::where('subject_type', Subject::class)->where('subject_id', $subject->id)->first();

    expect($actividad)->not->toBeNull()
        ->and($actividad->event)->toBe('created');

    tenancy()->end();
});

it('registra en activity_log un cambio en un subject', function () {
    $tenant = Tenant::create();
    tenancy()->initialize($tenant);

    $subject = Subject::factory()->create(['nivel_riesgo' => 'bajo']);
    $subject->update(['nivel_riesgo' => 'alto']);

    $actualizacion = Activity::where('subject_type', Subject::class)
        ->where('subject_id', $subject->id)
        ->where('event', 'updated')
        ->first();

    expect($actualizacion)->not->toBeNull();

    tenancy()->end();
});
