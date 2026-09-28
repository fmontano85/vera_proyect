<?php

declare(strict_types=1);

use App\Enums\EstadoSearchResult;
use App\Models\Activity;
use App\Models\Article;
use App\Models\SearchResult;
use App\Models\SearchRun;
use App\Models\SearchTag;
use App\Models\Subject;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Stancl\Tenancy\Database\Models\Tenant;

/**
 * Baja de tenant (seccion 3.9, punto 6 - bloque D): el superadmin exporta
 * todos los datos del tenant para devolverlos al cliente y despues lo da
 * de baja, borrando todo. Solo queda en la bitacora el registro de la baja.
 */
function tenantConDatos(string $nombre = 'Banco Uno'): array
{
    $tenant = Tenant::create(['name' => $nombre]);
    $admin = usuarioDeTenant($tenant, 'admin');

    tenancy()->initialize($tenant);
    $subject = Subject::factory()->create(['nombre_canonico' => "Persona de {$nombre}"]);
    // Busqueda por tags (sin persona) con evidencia manual.
    $runTags = SearchRun::factory()->create(['subject_id' => null, 'tags' => ['estafa']]);
    $resultadoTags = SearchResult::factory()->create(['search_run_id' => $runTags->id, 'subject_id' => null]);
    $resultadoTags->forceFill([
        'estado' => EstadoSearchResult::Extraido,
        'evidencia_manual_path' => "tenants/{$tenant->id}/evidencia-manual/{$resultadoTags->id}.pdf",
    ])->save();
    tenancy()->end();
    Storage::disk(config('vera.evidencia_manual_disk'))->put($resultadoTags->evidencia_manual_path, '%PDF-1.4');

    return compact('tenant', 'admin', 'subject', 'resultadoTags');
}

/** Exporta y consume la descarga completa: solo asi queda 'tenant_exportado' (requisito de la baja). */
function exportarTenant(Tenant $tenant, User $super): Illuminate\Testing\TestResponse
{
    $respuesta = test()->actingAs($super)->get("/api/superadmin/tenants/{$tenant->id}/exportar")->assertOk();
    $respuesta->streamedContent();

    return $respuesta;
}

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    Storage::fake(config('vera.evidencia_manual_disk'));
});

it('solo el superadmin exporta o da de baja un tenant', function (string $rol) {
    ['tenant' => $tenant] = tenantConDatos();
    $user = usuarioDeTenant($tenant, $rol);

    $this->actingAs($user)->get("/api/superadmin/tenants/{$tenant->id}/exportar")->assertForbidden();
    $this->actingAs($user)->postJson("/api/superadmin/tenants/{$tenant->id}/baja", ['confirmacion' => 'Banco Uno'])->assertForbidden();
})->with(['admin', 'oficial_cumplimiento', 'analista', 'lectura']);

it('exporta todos los datos del tenant en un ZIP y lo registra', function () {
    ['tenant' => $tenant, 'subject' => $subject, 'resultadoTags' => $resultadoTags] = tenantConDatos();

    $respuesta = exportarTenant($tenant, superadmin());

    expect($respuesta->headers->get('content-type'))->toBe('application/zip');
    $ruta = tempnam(sys_get_temp_dir(), 'exp');
    file_put_contents($ruta, $respuesta->streamedContent());
    $zip = new ZipArchive;
    expect($zip->open($ruta))->toBeTrue();
    $general = json_decode($zip->getFromName('tenant.json'), true);
    expect($general['tenant']['name'])->toBe('Banco Uno')
        ->and(collect($general['usuarios'])->pluck('rol')->all())->toContain('admin')
        ->and(json_decode($zip->getFromName("personas/{$subject->id}/persona.json"), true)['persona']['nombre_canonico'])->toBe('Persona de Banco Uno')
        ->and(json_decode($zip->getFromName('busquedas-por-tags.json'), true))->toHaveCount(1)
        ->and($zip->getFromName("busquedas-por-tags/evidencia-manual/resultado-{$resultadoTags->id}.pdf"))->toBe('%PDF-1.4')
        ->and($zip->getFromName('bitacora.json'))->not->toBeFalse();
    $zip->close();
    unlink($ruta);

    expect(Activity::where('event', 'tenant_exportado')->where('tenant_id', $tenant->id)->exists())->toBeTrue();
});

it('no da de baja sin una exportacion previa reciente', function () {
    ['tenant' => $tenant] = tenantConDatos();

    $this->actingAs(superadmin())->postJson("/api/superadmin/tenants/{$tenant->id}/baja", ['confirmacion' => 'Banco Uno'])
        ->assertUnprocessable();
    expect(Tenant::find($tenant->id))->not->toBeNull();
});

it('no da de baja si el nombre escrito no coincide', function () {
    ['tenant' => $tenant] = tenantConDatos();
    $super = superadmin();
    exportarTenant($tenant, $super);

    $this->actingAs($super)->postJson("/api/superadmin/tenants/{$tenant->id}/baja", ['confirmacion' => 'Otro Banco'])
        ->assertUnprocessable();
    expect(Tenant::find($tenant->id))->not->toBeNull();
});

it('la baja borra todos los datos del tenant y solo deja el registro de la baja', function () {
    ['tenant' => $tenant, 'admin' => $admin, 'resultadoTags' => $resultadoTags] = tenantConDatos();
    ['tenant' => $otro, 'subject' => $ajena] = tenantConDatos('Banco Dos');
    $articulo = Article::factory()->create();
    $super = superadmin();
    exportarTenant($tenant, $super);

    $this->actingAs($super)->postJson("/api/superadmin/tenants/{$tenant->id}/baja", ['confirmacion' => 'banco uno'])
        ->assertNoContent();

    $id = $tenant->id;
    expect(Tenant::find($id))->toBeNull()
        ->and(User::find($admin->id))->toBeNull()
        ->and(DB::table('subjects')->where('tenant_id', $id)->count())->toBe(0)
        ->and(DB::table('search_results')->where('tenant_id', $id)->count())->toBe(0)
        ->and(DB::table('search_runs')->where('tenant_id', $id)->count())->toBe(0)
        ->and(DB::table('search_tags')->where('tenant_id', $id)->count())->toBe(0)
        ->and(DB::table('frecuencias_seguimiento')->where('tenant_id', $id)->count())->toBe(0)
        ->and(Storage::disk(config('vera.evidencia_manual_disk'))->exists($resultadoTags->evidencia_manual_path))->toBeFalse()
        ->and(Activity::where('tenant_id', $id)->pluck('event')->all())->toBe(['tenant_dado_de_baja']);

    // El otro tenant y el contenido global no se tocan.
    expect(Tenant::find($otro->id))->not->toBeNull()
        ->and(DB::table('subjects')->where('id', $ajena->id)->exists())->toBeTrue()
        ->and(Article::find($articulo->id))->not->toBeNull();
    tenancy()->initialize($otro);
    expect(SearchTag::count())->toBeGreaterThan(0);
    tenancy()->end();
});

// ── Correcciones del /code-review high (2026-09-28) ─────────────────────

it('la exportacion del tenant solo habilita la baja cuando la descarga se entrega', function () {
    ['tenant' => $tenant] = tenantConDatos();
    $super = superadmin();

    $respuesta = $this->actingAs($super)->get("/api/superadmin/tenants/{$tenant->id}/exportar")->assertOk();
    $this->actingAs($super)->postJson("/api/superadmin/tenants/{$tenant->id}/baja", ['confirmacion' => 'Banco Uno'])
        ->assertUnprocessable();

    $respuesta->streamedContent();
    $this->actingAs($super)->postJson("/api/superadmin/tenants/{$tenant->id}/baja", ['confirmacion' => 'Banco Uno'])
        ->assertNoContent();
});

it('la baja no se salta personas al recorrer por lotes mientras borra', function () {
    ['tenant' => $tenant] = tenantConDatos();
    tenancy()->initialize($tenant);
    Subject::factory()->count(2)->create();
    tenancy()->end();
    $super = superadmin();
    exportarTenant($tenant, $super)->streamedContent();

    $accion = app(App\Actions\ProteccionDatos\DarDeBajaTenant::class);
    $accion->lote = 1;

    expect($accion->handle($tenant, $super)['personas'])->toBe(3);
});

it('un error inesperado durante la baja no se disfraza de 422', function () {
    ['tenant' => $tenant] = tenantConDatos();
    $this->mock(App\Actions\ProteccionDatos\DarDeBajaTenant::class)
        ->shouldReceive('handle')->andThrow(new RuntimeException('SQLSTATE[HY000]: Lock wait timeout'));

    $this->actingAs(superadmin())->postJson("/api/superadmin/tenants/{$tenant->id}/baja", ['confirmacion' => 'Banco Uno'])
        ->assertStatus(500);
});
