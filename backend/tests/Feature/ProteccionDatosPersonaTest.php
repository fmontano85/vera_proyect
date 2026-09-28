<?php

declare(strict_types=1);

use App\Enums\EstadoSearchResult;
use App\Enums\OrigenMention;
use App\Jobs\DepurarDatosVencidosJob;
use App\Models\Activity;
use App\Models\Alert;
use App\Models\Article;
use App\Models\Mention;
use App\Models\MentionMatch;
use App\Models\SanctionEntry;
use App\Models\SanctionList;
use App\Models\SanctionMatch;
use App\Models\SearchResult;
use App\Models\SearchRun;
use App\Models\Subject;
use App\Models\SubjectAlias;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Stancl\Tenancy\Database\Models\Tenant;

/**
 * Seccion 3.9, puntos 2 a 5 (bloque C): retencion por tenant (admin, piso
 * 15 anios), depuracion habilitada por el superadmin, exportacion y
 * borrado de una persona por el admin del tenant.
 */

/**
 * Persona con todo lo que VERA guarda de ella: alias, busqueda con
 * resultado + evidencia manual, mencion manual con su coincidencia,
 * mencion automatica (global, compartida) con coincidencia, hallazgo de
 * sanciones y alerta.
 *
 * @return array{subject: Subject, articulo: Article, mencionAutomatica: Mention, resultado: SearchResult}
 */
function personaCompleta(Tenant $tenant, string $nombre = 'Nombre Secreto Perez'): array
{
    tenancy()->initialize($tenant);
    $subject = Subject::factory()->create(['nombre_canonico' => $nombre, 'documento' => '01234567-8']);
    SubjectAlias::create(['subject_id' => $subject->id, 'nombre' => 'Alias Secreto']);
    $run = SearchRun::factory()->create(['subject_id' => $subject->id]);
    $resultado = SearchResult::factory()->create(['search_run_id' => $run->id, 'subject_id' => $subject->id]);
    $articulo = Article::factory()->create();
    $resultado->forceFill([
        'estado' => EstadoSearchResult::Extraido,
        'article_id' => $articulo->id,
        'evidencia_manual_path' => "tenants/{$tenant->id}/evidencia-manual/{$resultado->id}.pdf",
    ])->save();
    Storage::disk(config('vera.evidencia_manual_disk'))->put($resultado->evidencia_manual_path, '%PDF-1.4');

    $manual = Mention::factory()->create([
        'article_id' => null, 'search_result_id' => $resultado->id, 'nombre_extraido' => $nombre, 'confianza' => null,
    ]);
    $manual->forceFill(['origen' => OrigenMention::Manual])->save();
    MentionMatch::factory()->create(['mention_id' => $manual->id, 'subject_id' => $subject->id, 'estado' => 'confirmado']);
    $automatica = Mention::factory()->create(['article_id' => $articulo->id, 'search_result_id' => $resultado->id]);
    MentionMatch::factory()->create(['mention_id' => $automatica->id, 'subject_id' => $subject->id])->update(['estado' => 'homonimo']);
    // Una sola lista compartida: SanctionListFactory sortea 'codigo' (unico) y choca al repetir.
    $lista = SanctionList::query()->firstOrCreate(['codigo' => 'ofac_sdn'], ['version' => 'test', 'fecha_importacion' => now()]);
    SanctionMatch::factory()->create([
        'subject_id' => $subject->id,
        'sanction_entry_id' => SanctionEntry::factory()->for($lista, 'sanctionList')->create()->id,
    ]);
    Alert::create(['tipo' => 'seguimiento_pendiente', 'alertable_type' => Subject::class, 'alertable_id' => $subject->id, 'vencimiento' => '2026-09-01', 'canal' => 'correo']);
    tenancy()->end();

    return ['subject' => $subject, 'articulo' => $articulo, 'mencionAutomatica' => $automatica, 'resultado' => $resultado];
}

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    Storage::fake(config('vera.evidencia_manual_disk'));
});

// ── Retencion ──────────────────────────────────────────────────────────

it('el plazo de retencion es 15 anios por defecto y solo el admin lo cambia, nunca por debajo de 15', function () {
    $tenant = Tenant::create();
    $admin = usuarioDeTenant($tenant, 'admin');
    $oficial = usuarioDeTenant($tenant, 'oficial_cumplimiento');

    $this->actingAs($oficial)->getJson('/api/configuracion/retencion')->assertOk()->assertJsonPath('retencion_anios', 15);
    $this->actingAs($oficial)->putJson('/api/configuracion/retencion', ['retencion_anios' => 20])->assertForbidden();
    $this->actingAs($admin)->putJson('/api/configuracion/retencion', ['retencion_anios' => 14])->assertUnprocessable();
    $this->actingAs($admin)->putJson('/api/configuracion/retencion', ['retencion_anios' => 20])->assertOk()->assertJsonPath('retencion_anios', 20);

    expect((int) $tenant->fresh()->retencion_anios)->toBe(20)
        ->and(Activity::where('event', 'retencion_cambiada')->where('tenant_id', $tenant->id)->exists())->toBeTrue();
});

it('guarda cuando se desactiva una persona y lo limpia al reactivarla', function () {
    $tenant = Tenant::create();
    tenancy()->initialize($tenant);
    $subject = Subject::factory()->create();
    expect($subject->desactivado_en)->toBeNull();

    $subject->update(['activo' => false]);
    expect($subject->fresh()->desactivado_en)->not->toBeNull();

    $subject->update(['activo' => true]);
    expect($subject->fresh()->desactivado_en)->toBeNull();
    tenancy()->end();
});

// ── Borrado por orden del cliente ──────────────────────────────────────

it('solo el admin borra una persona y debe confirmar escribiendo su nombre', function () {
    $tenant = Tenant::create();
    ['subject' => $subject] = personaCompleta($tenant);

    $this->actingAs(usuarioDeTenant($tenant, 'oficial_cumplimiento'))
        ->deleteJson("/api/subjects/{$subject->id}", ['confirmacion' => 'Nombre Secreto Perez'])->assertForbidden();
    $admin = usuarioDeTenant($tenant, 'admin');
    $this->actingAs($admin)->deleteJson("/api/subjects/{$subject->id}", ['confirmacion' => 'otro nombre'])->assertUnprocessable();

    expect(Subject::withoutGlobalScopes()->find($subject->id))->not->toBeNull();
});

it('borra la persona y todo lo que es del tenant, conserva lo global', function () {
    $tenant = Tenant::create();
    ['subject' => $subject, 'articulo' => $articulo, 'mencionAutomatica' => $automatica, 'resultado' => $resultado] = personaCompleta($tenant);
    $admin = usuarioDeTenant($tenant, 'admin');

    $this->actingAs($admin)->deleteJson("/api/subjects/{$subject->id}", ['confirmacion' => 'nombre secreto perez'])->assertNoContent();

    expect(Subject::withoutGlobalScopes()->find($subject->id))->toBeNull()
        ->and(DB::table('subject_aliases')->where('subject_id', $subject->id)->count())->toBe(0)
        ->and(DB::table('matches')->where('subject_id', $subject->id)->count())->toBe(0)
        ->and(DB::table('sanction_matches')->where('subject_id', $subject->id)->count())->toBe(0)
        ->and(DB::table('search_results')->where('subject_id', $subject->id)->count())->toBe(0)
        ->and(DB::table('search_runs')->where('subject_id', $subject->id)->count())->toBe(0)
        ->and(DB::table('mentions')->where('origen', 'manual')->count())->toBe(0)
        ->and(DB::table('alerts')->where('alertable_id', $subject->id)->count())->toBe(0)
        ->and(Storage::disk(config('vera.evidencia_manual_disk'))->exists($resultado->evidencia_manual_path))->toBeFalse()
        // Contenido publico global: se conserva, solo pierde el enlace al resultado borrado.
        ->and(Article::find($articulo->id))->not->toBeNull()
        ->and(Mention::find($automatica->id)?->search_result_id)->toBeNull();
});

it('la bitacora conserva los eventos de la persona borrada pero sin sus datos personales', function () {
    $tenant = Tenant::create();
    ['subject' => $subject] = personaCompleta($tenant);
    $admin = usuarioDeTenant($tenant, 'admin');
    $this->actingAs($admin)->patchJson("/api/subjects/{$subject->id}", ['nivel_riesgo' => 'alto'])->assertOk();

    $this->actingAs($admin)->deleteJson("/api/subjects/{$subject->id}", ['confirmacion' => 'Nombre Secreto Perez'])->assertNoContent();

    $registros = Activity::where('tenant_id', $tenant->id)->get();
    $texto = $registros->toJson();
    expect($texto)->not->toContain('Nombre Secreto')
        ->and($texto)->not->toContain('Alias Secreto')
        ->and($texto)->not->toContain('01234567-8')
        ->and($registros->where('subject_type', Subject::class)->where('event', 'updated')->count())->toBe(1);

    $borrado = $registros->firstWhere('event', 'persona_eliminada');
    expect($borrado)->not->toBeNull()
        ->and($borrado->getProperty('motivo'))->toBe('orden_del_cliente')
        ->and((int) $borrado->subject_id)->toBe($subject->id)
        ->and((int) $borrado->causer_id)->toBe($admin->id);
});

it('borrar una persona no toca a otra del mismo tenant ni a otro tenant', function () {
    $tenant = Tenant::create();
    $otro = Tenant::create();
    ['subject' => $borrar] = personaCompleta($tenant, 'Persona A');
    ['subject' => $queda] = personaCompleta($tenant, 'Persona B');
    ['subject' => $ajena] = personaCompleta($otro, 'Persona C');

    $this->actingAs(usuarioDeTenant($tenant, 'admin'))->deleteJson("/api/subjects/{$borrar->id}", ['confirmacion' => 'Persona A'])->assertNoContent();

    expect(DB::table('matches')->where('subject_id', $queda->id)->count())->toBe(2)
        ->and(DB::table('matches')->where('subject_id', $ajena->id)->count())->toBe(2)
        ->and(Activity::where('subject_type', Subject::class)->where('subject_id', $queda->id)->first()->attribute_changes)->not->toBeNull();
});

it('un admin no borra personas de otro tenant (404)', function () {
    ['subject' => $ajena] = personaCompleta(Tenant::create(), 'Persona C');

    $this->actingAs(usuarioDeTenant(Tenant::create(), 'admin'))
        ->deleteJson("/api/subjects/{$ajena->id}", ['confirmacion' => 'Persona C'])->assertNotFound();
});

// ── Exportacion ────────────────────────────────────────────────────────

it('el admin exporta todo lo que VERA tiene de una persona en un ZIP y queda en la bitacora', function () {
    $tenant = Tenant::create();
    ['subject' => $subject, 'resultado' => $resultado] = personaCompleta($tenant);
    $admin = usuarioDeTenant($tenant, 'admin');

    $respuesta = $this->actingAs($admin)->get("/api/subjects/{$subject->id}/exportar")->assertOk();

    expect($respuesta->headers->get('content-type'))->toBe('application/zip')
        ->and($respuesta->headers->get('content-disposition'))->toContain('attachment');

    $zipPath = tempnam(sys_get_temp_dir(), 'exp');
    file_put_contents($zipPath, $respuesta->streamedContent());
    $zip = new ZipArchive;
    expect($zip->open($zipPath))->toBeTrue();
    $datos = json_decode($zip->getFromName('persona.json'), true);
    expect($datos['persona']['nombre_canonico'])->toBe('Nombre Secreto Perez')
        ->and($datos['aliases'])->toBe(['Alias Secreto'])
        ->and($datos['resultados'])->toHaveCount(1)
        ->and($datos['coincidencias'])->toHaveCount(2)
        ->and($datos['sanciones'])->toHaveCount(1)
        ->and($zip->getFromName("evidencia-manual/resultado-{$resultado->id}.pdf"))->toBe('%PDF-1.4');
    $zip->close();
    unlink($zipPath);

    expect(Activity::where('event', 'datos_exportados')->where('subject_id', $subject->id)->exists())->toBeTrue();
});

it('solo el admin exporta', function (string $rol) {
    $tenant = Tenant::create();
    ['subject' => $subject] = personaCompleta($tenant);

    $this->actingAs(usuarioDeTenant($tenant, $rol))->get("/api/subjects/{$subject->id}/exportar")->assertForbidden();
})->with(['oficial_cumplimiento', 'analista', 'lectura']);

// ── Depuracion ─────────────────────────────────────────────────────────

it('el superadmin habilita o deshabilita la depuracion por tenant', function () {
    $tenant = Tenant::create();

    $this->actingAs(superadmin())->patchJson("/api/superadmin/tenants/{$tenant->id}", ['depuracion_habilitada' => true])
        ->assertOk()->assertJsonPath('depuracion_habilitada', true);
    $this->actingAs(usuarioDeTenant($tenant, 'admin'))->getJson('/api/configuracion/retencion')
        ->assertJsonPath('depuracion_habilitada', true);

    expect(Activity::where('event', 'depuracion_cambiada')->where('tenant_id', $tenant->id)->exists())->toBeTrue();
});

it('la depuracion borra solo personas inactivas con el plazo vencido y solo en tenants habilitados', function () {
    $habilitado = Tenant::create();
    $habilitado->update(['depuracion_habilitada' => true]);
    $apagado = Tenant::create();

    ['subject' => $vencida] = personaCompleta($habilitado, 'Vencida');
    ['subject' => $reciente] = personaCompleta($habilitado, 'Reciente');
    ['subject' => $activa] = personaCompleta($habilitado, 'Activa');
    ['subject' => $deOtro] = personaCompleta($apagado, 'Otro Tenant');
    DB::table('subjects')->whereIn('id', [$vencida->id, $deOtro->id])->update(['activo' => false, 'desactivado_en' => now()->subYears(16)]);
    DB::table('subjects')->where('id', $reciente->id)->update(['activo' => false, 'desactivado_en' => now()->subYears(14)]);
    DB::table('subjects')->where('id', $activa->id)->update(['desactivado_en' => null]);

    (new DepurarDatosVencidosJob)->handle(app(App\Actions\ProteccionDatos\BorrarSubject::class));

    $quedan = DB::table('subjects')->pluck('id')->all();
    expect($quedan)->not->toContain($vencida->id)
        ->and($quedan)->toContain($reciente->id)
        ->and($quedan)->toContain($activa->id)
        ->and($quedan)->toContain($deOtro->id);

    $registro = Activity::where('event', 'persona_eliminada')->sole();
    expect($registro->getProperty('motivo'))->toBe('plazo_de_retencion')
        ->and($registro->tenant_id)->toBe($habilitado->id)
        ->and(Activity::where('event', 'depuracion_ejecutada')->where('tenant_id', $habilitado->id)->sole()->getProperty('personas_eliminadas'))->toBe(1);
});

it('respeta un plazo de retencion mayor configurado por el tenant', function () {
    $tenant = Tenant::create();
    $tenant->update(['depuracion_habilitada' => true, 'retencion_anios' => 20]);
    ['subject' => $persona] = personaCompleta($tenant, 'Con Plazo Largo');
    DB::table('subjects')->where('id', $persona->id)->update(['activo' => false, 'desactivado_en' => now()->subYears(16)]);

    (new DepurarDatosVencidosJob)->handle(app(App\Actions\ProteccionDatos\BorrarSubject::class));

    expect(DB::table('subjects')->where('id', $persona->id)->exists())->toBeTrue();
});
