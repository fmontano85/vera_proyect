<?php

declare(strict_types=1);

use App\Jobs\GenerarReporteJob;
use App\Models\Activity;
use App\Models\Mention;
use App\Models\MentionMatch;
use App\Models\Report;
use App\Models\Subject;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Stancl\Tenancy\Database\Models\Tenant;

/**
 * Reportes de auditoria exportables (seccion 1, punto 5): ficha de
 * persona, actividad de un periodo y lista por nivel de riesgo, en PDF o
 * CSV, generados en segundo plano. Nunca afirman involucramiento sin
 * resolucion humana: lo pendiente se rotula "Pendiente de resolucion".
 */
function personaConCoincidencias(Tenant $tenant, string $nombre = 'Ana Lopez', string $nivel = 'alto'): Subject
{
    tenancy()->initialize($tenant);
    $subject = Subject::factory()->create(['nombre_canonico' => $nombre, 'nivel_riesgo' => $nivel]);
    $confirmada = MentionMatch::factory()->create([
        'subject_id' => $subject->id,
        'mention_id' => Mention::factory()->create(['nombre_extraido' => $nombre, 'rol' => 'imputado', 'delitos' => ['estafa']])->id,
    ]);
    $confirmada->forceFill(['estado' => 'confirmado', 'resuelto_en' => '2026-09-10 15:00:00'])->save();
    MentionMatch::factory()->create([
        'subject_id' => $subject->id,
        'mention_id' => Mention::factory()->create(['nombre_extraido' => "{$nombre} B", 'rol' => 'testigo', 'delitos' => []])->id,
    ]);
    tenancy()->end();

    return $subject;
}

/** Solicita el reporte por la API, corre el job en el acto y devuelve el reporte ya generado. */
function generarReporte(Tenant $tenant, array $datos, string $rol = 'oficial_cumplimiento'): Report
{
    Bus::fake();
    $id = test()->actingAs(usuarioDeTenant($tenant, $rol))->postJson('/api/reportes', $datos)->assertStatus(202)->json('id');
    (new GenerarReporteJob($id, $tenant->id))->handle(app(App\Services\Reportes\ConstructorReportes::class), app(App\Services\Evidence\GeneradorPdf::class));

    return Report::withoutGlobalScopes()->findOrFail($id);
}

function csvDe(Report $reporte): string
{
    return (string) Storage::disk(config('vera.evidencia_manual_disk'))->get($reporte->archivo_path);
}

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    Storage::fake(config('vera.evidencia_manual_disk'));
});

it('solicitar un reporte lo deja pendiente y encola su generacion', function () {
    Bus::fake();
    $tenant = Tenant::create();
    $subject = personaConCoincidencias($tenant);

    $respuesta = $this->actingAs(usuarioDeTenant($tenant, 'lectura'))
        ->postJson('/api/reportes', ['tipo' => 'ficha_persona', 'formato' => 'pdf', 'subject_id' => $subject->id])
        ->assertStatus(202)->assertJsonPath('estado', 'pendiente');

    Bus::assertDispatched(GenerarReporteJob::class, fn (GenerarReporteJob $job) => $job->reporteId === $respuesta->json('id'));
    expect(Activity::where('event', 'reporte_solicitado')->where('tenant_id', $tenant->id)->exists())->toBeTrue();
});

it('genera la ficha de persona en PDF', function () {
    $tenant = Tenant::create();
    $subject = personaConCoincidencias($tenant);

    $reporte = generarReporte($tenant, ['tipo' => 'ficha_persona', 'formato' => 'pdf', 'subject_id' => $subject->id]);

    expect($reporte->estado)->toBe('listo')
        ->and(Illuminate\Support\Facades\DB::table('report_subject')->where('report_id', $reporte->id)->pluck('subject_id')->all())->toBe([$subject->id])
        ->and(substr((string) Storage::disk(config('vera.evidencia_manual_disk'))->get($reporte->archivo_path), 0, 4))->toBe('%PDF');
});

it('la ficha en CSV rotula lo no resuelto como pendiente de resolucion', function () {
    $tenant = Tenant::create();
    $subject = personaConCoincidencias($tenant);

    $csv = csvDe(generarReporte($tenant, ['tipo' => 'ficha_persona', 'formato' => 'csv', 'subject_id' => $subject->id]));

    expect($csv)->toStartWith("\u{FEFF}")
        ->and($csv)->toContain('Ana Lopez')
        ->and($csv)->toContain('Confirmada')
        ->and($csv)->toContain('Pendiente de resolución')
        ->and($csv)->toContain('estafa');
});

it('el reporte de actividad incluye solo lo resuelto dentro del periodo', function () {
    $tenant = Tenant::create();
    personaConCoincidencias($tenant, 'Dentro Del Periodo');
    $fuera = personaConCoincidencias($tenant, 'Fuera Del Periodo');
    MentionMatch::withoutGlobalScopes()->where('subject_id', $fuera->id)->where('estado', 'confirmado')
        ->update(['resuelto_en' => '2026-08-01 12:00:00']);

    $csv = csvDe(generarReporte($tenant, ['tipo' => 'actividad_periodo', 'formato' => 'csv', 'desde' => '2026-09-01', 'hasta' => '2026-09-30']));

    expect($csv)->toContain('Dentro Del Periodo')->and($csv)->not->toContain('Fuera Del Periodo');
});

it('la lista por nivel de riesgo filtra por nivel y cuenta coincidencias por estado', function () {
    $tenant = Tenant::create();
    personaConCoincidencias($tenant, 'Riesgo Alto', 'alto');
    personaConCoincidencias($tenant, 'Riesgo Bajo', 'bajo');

    $csv = csvDe(generarReporte($tenant, ['tipo' => 'lista_por_riesgo', 'formato' => 'csv', 'nivel_riesgo' => 'alto']));

    expect($csv)->toContain('Riesgo Alto')->and($csv)->not->toContain('Riesgo Bajo');
    $fila = collect(explode("\n", $csv))->first(fn ($l) => str_contains($l, 'Riesgo Alto'));
    expect(str_getcsv($fila))->toContain('1');
});

it('neutraliza formulas en las celdas del CSV (inyeccion de formulas)', function () {
    $tenant = Tenant::create();
    $subject = personaConCoincidencias($tenant, '=HYPERLINK("http://malo.example")');

    $csv = csvDe(generarReporte($tenant, ['tipo' => 'ficha_persona', 'formato' => 'csv', 'subject_id' => $subject->id]));

    expect($csv)->toContain("'=HYPERLINK")->and($csv)->not->toMatch('/(^|,)"?=HYPERLINK/m');
});

it('valida los parametros de cada tipo de reporte', function () {
    $tenant = Tenant::create();
    $user = usuarioDeTenant($tenant, 'analista');
    $ajena = personaConCoincidencias(Tenant::create());

    $this->actingAs($user)->postJson('/api/reportes', ['tipo' => 'ficha_persona', 'formato' => 'pdf'])->assertUnprocessable();
    $this->actingAs($user)->postJson('/api/reportes', ['tipo' => 'ficha_persona', 'formato' => 'pdf', 'subject_id' => $ajena->id])->assertUnprocessable();
    $this->actingAs($user)->postJson('/api/reportes', ['tipo' => 'actividad_periodo', 'formato' => 'csv', 'desde' => '2026-09-30', 'hasta' => '2026-09-01'])->assertUnprocessable();
    $this->actingAs($user)->postJson('/api/reportes', ['tipo' => 'actividad_periodo', 'formato' => 'csv', 'desde' => '2025-01-01', 'hasta' => '2026-09-01'])->assertUnprocessable();
    $this->actingAs($user)->postJson('/api/reportes', ['tipo' => 'lista_por_riesgo', 'formato' => 'xlsx', 'nivel_riesgo' => 'alto'])->assertUnprocessable();
});

it('lista solo los reportes del tenant y descarga solo los listos, registrandolo', function () {
    $tenant = Tenant::create();
    $otro = Tenant::create();
    $subject = personaConCoincidencias($tenant);
    $listo = generarReporte($tenant, ['tipo' => 'ficha_persona', 'formato' => 'csv', 'subject_id' => $subject->id]);
    $ajeno = generarReporte($otro, ['tipo' => 'lista_por_riesgo', 'formato' => 'csv', 'nivel_riesgo' => 'todos']);
    $user = usuarioDeTenant($tenant, 'lectura');

    expect(collect($this->actingAs($user)->getJson('/api/reportes')->assertOk()->json('data'))->pluck('id')->all())->toBe([$listo->id]);

    $descarga = $this->actingAs($user)->get("/api/reportes/{$listo->id}/descargar")->assertOk();
    expect($descarga->headers->get('content-disposition'))->toContain('attachment')
        ->and(Activity::where('event', 'reporte_descargado')->where('tenant_id', $tenant->id)->exists())->toBeTrue();
    $this->actingAs($user)->get("/api/reportes/{$ajeno->id}/descargar")->assertNotFound();

    $pendiente = Report::withoutGlobalScopes()->findOrFail($listo->id)->replicate();
    $pendiente->forceFill(['estado' => 'pendiente', 'archivo_path' => null])->save();
    $this->actingAs($user)->get("/api/reportes/{$pendiente->id}/descargar")->assertStatus(409);
});

it('borrar una persona borra los reportes que la incluyen', function () {
    $tenant = Tenant::create();
    $subject = personaConCoincidencias($tenant);
    $reporte = generarReporte($tenant, ['tipo' => 'ficha_persona', 'formato' => 'csv', 'subject_id' => $subject->id]);

    $this->actingAs(usuarioDeTenant($tenant, 'admin'))
        ->deleteJson("/api/subjects/{$subject->id}", ['confirmacion' => 'Ana Lopez'])->assertNoContent();

    expect(Report::withoutGlobalScopes()->find($reporte->id))->toBeNull()
        ->and(Storage::disk(config('vera.evidencia_manual_disk'))->exists($reporte->archivo_path))->toBeFalse();
});

it('si la generacion falla el reporte queda como fallido', function () {
    $tenant = Tenant::create();
    $reporte = generarReporte($tenant, ['tipo' => 'lista_por_riesgo', 'formato' => 'csv', 'nivel_riesgo' => 'todos']);
    $reporte->forceFill(['estado' => 'generando'])->save();

    (new GenerarReporteJob($reporte->id, $tenant->id))->failed(new RuntimeException('disco lleno'));

    expect($reporte->fresh()->estado)->toBe('fallido');
});

it('una ficha todavia en cola tambien se borra al borrar a la persona, y muestra su nombre', function () {
    Bus::fake();
    $tenant = Tenant::create();
    $subject = personaConCoincidencias($tenant);
    $admin = usuarioDeTenant($tenant, 'admin');
    $id = $this->actingAs($admin)->postJson('/api/reportes', ['tipo' => 'ficha_persona', 'formato' => 'pdf', 'subject_id' => $subject->id])
        ->assertStatus(202)->assertJsonPath('parametros.nombre', 'Ana Lopez')->json('id');

    $this->actingAs($admin)->deleteJson("/api/subjects/{$subject->id}", ['confirmacion' => 'Ana Lopez'])->assertNoContent();

    expect(Report::withoutGlobalScopes()->find($id))->toBeNull();
});

// ── Correcciones del /code-review high (2026-09-28) ─────────────────────

it('si una persona del reporte se borra mientras se genera, no queda archivo con sus datos', function () {
    $tenant = Tenant::create();
    tenancy()->initialize($tenant);
    $subject = Subject::factory()->create(['nombre_canonico' => 'Ana Lopez']);
    tenancy()->end();
    Bus::fake();
    $id = $this->actingAs(usuarioDeTenant($tenant, 'oficial_cumplimiento'))
        ->postJson('/api/reportes', ['tipo' => 'lista_por_riesgo', 'formato' => 'csv', 'nivel_riesgo' => 'todos'])->json('id');

    // El constructor ya leyo a la persona; otra peticion la borra antes de que el job termine.
    $constructor = Mockery::mock(App\Services\Reportes\ConstructorReportes::class);
    $constructor->shouldReceive('construir')->andReturnUsing(function () use ($subject) {
        Illuminate\Support\Facades\DB::table('subjects')->where('id', $subject->id)->delete();

        return ['titulo' => 't', 'vista' => 'x', 'orientacion' => 'portrait', 'datos' => [],
            'csv' => ['encabezados' => ['Persona'], 'filas' => [['Ana Lopez']]], 'personas' => [$subject->id]];
    });
    (new GenerarReporteJob($id, $tenant->id))->handle($constructor, app(App\Services\Evidence\GeneradorPdf::class));

    $reporte = Report::withoutGlobalScopes()->find($id);
    expect($reporte->estado)->toBe('fallido')
        ->and($reporte->archivo_path)->toBeNull()
        ->and(Storage::disk(config('vera.evidencia_manual_disk'))->allFiles("tenants/{$tenant->id}/reportes"))->toBe([]);
});

it('si no se puede encolar la generacion, el reporte queda fallido y no pendiente para siempre', function () {
    $tenant = Tenant::create();
    $this->mock(Illuminate\Contracts\Bus\Dispatcher::class)
        ->shouldReceive('dispatch')->andThrow(new RuntimeException('Redis no disponible'));

    $this->actingAs(usuarioDeTenant($tenant, 'analista'))
        ->postJson('/api/reportes', ['tipo' => 'lista_por_riesgo', 'formato' => 'csv', 'nivel_riesgo' => 'todos'])
        ->assertStatus(500);

    expect(Report::withoutGlobalScopes()->where('tenant_id', $tenant->id)->pluck('estado')->all())->toBe(['fallido']);
});

it('la ficha cuenta como revisados solo los resultados procesados o descartados', function () {
    $tenant = Tenant::create();
    $subject = personaConCoincidencias($tenant);
    tenancy()->initialize($tenant);
    App\Models\SearchResult::factory()->count(2)->create(['subject_id' => $subject->id]);
    $revisado = App\Models\SearchResult::factory()->create(['subject_id' => $subject->id]);
    $revisado->forceFill(['estado' => App\Enums\EstadoSearchResult::Descartado])->save();
    $reporte = Report::create(['tipo' => 'ficha_persona', 'formato' => 'pdf', 'parametros' => ['subject_id' => $subject->id]]);
    $datos = app(App\Services\Reportes\ConstructorReportes::class)->construir($reporte)['datos'];
    tenancy()->end();

    expect($datos['resultados'])->toBe(1);
});
