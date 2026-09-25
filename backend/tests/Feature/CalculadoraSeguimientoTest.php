<?php

declare(strict_types=1);

use App\Models\FrecuenciaSeguimiento;
use App\Models\Subject;
use App\Services\Seguimiento\CalculadoraSeguimiento;
use Carbon\Carbon;
use Stancl\Tenancy\Database\Models\Tenant;

/**
 * Seccion 3.8 del CLAUDE.md raiz (Fase 2, agenda de seguimiento).
 * Defaults confirmados por el usuario 2026-09-25: alto 30, medio 90,
 * bajo 180, sin nivel 180.
 */
afterEach(function () {
    tenancy()->end();
    Carbon::setTestNow();
});

it('siembra las frecuencias por defecto al crear un tenant', function () {
    $tenant = Tenant::create();
    tenancy()->initialize($tenant);

    expect(FrecuenciaSeguimiento::pluck('dias', 'nivel_riesgo')->all())->toEqual([
        'alto' => 30,
        'medio' => 90,
        'bajo' => 180,
        'sin_nivel' => 180,
    ]);
});

it('usa la frecuencia del nivel de riesgo cuando el subject no tiene una propia', function () {
    tenancy()->initialize(Tenant::create());
    $subject = Subject::factory()->create(['nivel_riesgo' => 'alto', 'frecuencia_seguimiento_dias' => null]);

    expect(app(CalculadoraSeguimiento::class)->frecuenciaEfectiva($subject))
        ->toBe(['dias' => 30, 'origen' => 'nivel']);
});

it('usa sin_nivel cuando el subject no tiene nivel de riesgo', function () {
    tenancy()->initialize(Tenant::create());
    $subject = Subject::factory()->create(['nivel_riesgo' => null]);

    expect(app(CalculadoraSeguimiento::class)->frecuenciaEfectiva($subject)['dias'])->toBe(180);
});

it('la frecuencia personalizada del subject manda sobre la del nivel', function () {
    tenancy()->initialize(Tenant::create());
    $subject = Subject::factory()->create(['nivel_riesgo' => 'alto', 'frecuencia_seguimiento_dias' => 7]);

    expect(app(CalculadoraSeguimiento::class)->frecuenciaEfectiva($subject))
        ->toBe(['dias' => 7, 'origen' => 'personalizada']);
});

it('respeta la frecuencia configurada por el tenant, no la constante por defecto', function () {
    tenancy()->initialize(Tenant::create());
    FrecuenciaSeguimiento::where('nivel_riesgo', 'medio')->update(['dias' => 45]);
    $subject = Subject::factory()->create(['nivel_riesgo' => 'medio']);

    expect(app(CalculadoraSeguimiento::class)->frecuenciaEfectiva($subject)['dias'])->toBe(45);
});

it('al crear un subject calcula proximo_seguimiento_en desde la fecha de alta', function () {
    Carbon::setTestNow('2026-09-25 10:00:00');
    tenancy()->initialize(Tenant::create());

    $subject = Subject::factory()->create(['nivel_riesgo' => 'alto']);

    expect($subject->proximo_seguimiento_en->toDateString())->toBe('2026-10-25');
});

it('al cambiar el nivel de riesgo recalcula desde el ultimo seguimiento', function () {
    Carbon::setTestNow('2026-09-25 10:00:00');
    tenancy()->initialize(Tenant::create());
    $subject = Subject::factory()->create(['nivel_riesgo' => 'bajo']);
    $subject->forceFill(['ultimo_seguimiento_en' => Carbon::parse('2026-09-01 12:00:00')])->save();

    $subject->update(['nivel_riesgo' => 'alto']);

    expect($subject->fresh()->proximo_seguimiento_en->toDateString())->toBe('2026-10-01');
});

it('al cambiar la frecuencia personalizada recalcula la proxima fecha', function () {
    Carbon::setTestNow('2026-09-25 10:00:00');
    tenancy()->initialize(Tenant::create());
    $subject = Subject::factory()->create(['nivel_riesgo' => 'bajo']);

    $subject->update(['frecuencia_seguimiento_dias' => 10]);

    expect($subject->fresh()->proximo_seguimiento_en->toDateString())->toBe('2026-10-05');
});

it('calcula hoy en la zona horaria de El Salvador, no en UTC', function () {
    // 2026-09-26 03:00 UTC = 2026-09-25 21:00 en El Salvador (UTC-6).
    Carbon::setTestNow(Carbon::parse('2026-09-26 03:00:00', 'UTC'));

    expect(app(CalculadoraSeguimiento::class)->hoy()->toDateString())->toBe('2026-09-25');
});

it('vera:inicializar-seguimientos siembra frecuencias y fecha a subjects existentes sin proximo_seguimiento_en, y es idempotente', function () {
    Carbon::setTestNow('2026-09-25 10:00:00');
    $tenant = Tenant::create();
    tenancy()->initialize($tenant);
    $subject = Subject::factory()->create(['nivel_riesgo' => 'medio']);
    // Simula un tenant/subject anterior a la seccion 3.8.
    FrecuenciaSeguimiento::query()->delete();
    Subject::whereKey($subject->id)->update(['proximo_seguimiento_en' => null]);
    tenancy()->end();

    $this->artisan('vera:inicializar-seguimientos')->assertSuccessful();
    $this->artisan('vera:inicializar-seguimientos')->assertSuccessful();

    tenancy()->initialize($tenant);
    expect(FrecuenciaSeguimiento::count())->toBe(4)
        ->and($subject->fresh()->proximo_seguimiento_en)->not->toBeNull();
});

it('serializa proximo_seguimiento_en como fecha de calendario Y-m-d, sin hora ni zona', function () {
    Carbon::setTestNow('2026-09-25 10:00:00');
    tenancy()->initialize(Tenant::create());
    $subject = Subject::factory()->create(['nivel_riesgo' => 'alto']);

    expect($subject->fresh()->toArray()['proximo_seguimiento_en'])->toBe('2026-10-25');
});

it('marcar un seguimiento o cambiar la frecuencia no reindexa en Meilisearch; cambiar el nombre si', function () {
    tenancy()->initialize(Tenant::create());
    // Instancia recargada, como llega por route binding (una recien
    // creada conserva wasRecentlyCreated = true).
    $subject = Subject::factory()->create(['nivel_riesgo' => 'alto'])->fresh();

    $subject->forceFill(['ultimo_seguimiento_en' => now(), 'frecuencia_seguimiento_dias' => 10, 'nivel_riesgo' => 'bajo'])->save();
    expect($subject->searchIndexShouldBeUpdated())->toBeFalse();

    $subject->update(['nombre_canonico' => 'Otro Nombre']);
    expect($subject->searchIndexShouldBeUpdated())->toBeTrue();

    $subject->update(['activo' => false]);
    expect($subject->searchIndexShouldBeUpdated())->toBeTrue();
});

it('crear un tenant dentro del contexto de otro no deja la tenancy terminada: restaura el tenant anterior', function () {
    $original = Tenant::create();
    tenancy()->initialize($original);

    Tenant::create();

    expect(tenant('id'))->toBe($original->id);
});
