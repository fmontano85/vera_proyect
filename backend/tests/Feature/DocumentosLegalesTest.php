<?php

declare(strict_types=1);

use App\Models\AceptacionDocumento;
use App\Models\Activity;
use App\Models\DocumentoLegal;
use App\Models\Source;
use App\Models\Subject;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Bus;
use Stancl\Tenancy\Database\Models\Tenant;

/**
 * Terminos y contrato de encargo (seccion 3.9, punto 1 - bloque B): el
 * superadmin los redacta y publica por version; el admin del tenant acepta
 * la version vigente; sin aceptarla el tenant no crea personas ni busca.
 */
function publicado(string $tipo = 'terminos', string $contenido = 'Texto de los terminos'): DocumentoLegal
{
    $super = superadmin();
    $id = test()->actingAs($super)->postJson('/api/superadmin/documentos-legales', [
        'tipo' => $tipo, 'titulo' => 'Titulo', 'contenido' => $contenido,
    ])->assertCreated()->json('id');
    test()->actingAs($super)->postJson("/api/superadmin/documentos-legales/{$id}/publicar")->assertOk();

    return DocumentoLegal::findOrFail($id);
}

beforeEach(fn () => $this->seed(RoleSeeder::class));

it('solo el superadmin gestiona los documentos legales', function (string $rol) {
    $user = usuarioDeTenant(Tenant::create(), $rol);

    $this->actingAs($user)->getJson('/api/superadmin/documentos-legales')->assertForbidden();
    $this->actingAs($user)->postJson('/api/superadmin/documentos-legales', ['tipo' => 'terminos', 'titulo' => 'x', 'contenido' => 'x'])->assertForbidden();
})->with(['admin', 'oficial_cumplimiento', 'analista', 'lectura']);

it('crea un borrador, lo edita y al publicarlo le asigna la version siguiente de su tipo', function () {
    $super = superadmin();

    $id = $this->actingAs($super)->postJson('/api/superadmin/documentos-legales', [
        'tipo' => 'terminos', 'titulo' => 'Terminos v1', 'contenido' => 'Borrador',
    ])->assertCreated()->assertJsonPath('estado', 'borrador')->assertJsonPath('version', null)->json('id');

    $this->actingAs($super)->putJson("/api/superadmin/documentos-legales/{$id}", ['titulo' => 'Terminos', 'contenido' => 'Texto final'])
        ->assertOk()->assertJsonPath('contenido', 'Texto final');

    $this->actingAs($super)->postJson("/api/superadmin/documentos-legales/{$id}/publicar")
        ->assertOk()->assertJsonPath('version', 1)->assertJsonPath('estado', 'vigente');

    $segundo = publicado('terminos');
    expect($segundo->version)->toBe(2)
        ->and(publicado('contrato_encargo')->version)->toBe(1);

    $listado = collect($this->actingAs($super)->getJson('/api/superadmin/documentos-legales')->assertOk()->json());
    expect($listado->firstWhere('id', $id)['estado'])->toBe('anterior')
        ->and($listado->firstWhere('id', $segundo->id)['estado'])->toBe('vigente');
});

it('una version publicada no se edita, no se borra ni se republica', function () {
    $super = superadmin();
    $doc = publicado();

    $this->actingAs($super)->putJson("/api/superadmin/documentos-legales/{$doc->id}", ['titulo' => 'x', 'contenido' => 'x'])->assertUnprocessable();
    $this->actingAs($super)->deleteJson("/api/superadmin/documentos-legales/{$doc->id}")->assertUnprocessable();
    $this->actingAs($super)->postJson("/api/superadmin/documentos-legales/{$doc->id}/publicar")->assertUnprocessable();
});

it('solo hay un borrador por tipo a la vez, y un borrador se puede descartar', function () {
    $super = superadmin();
    $datos = ['tipo' => 'terminos', 'titulo' => 'x', 'contenido' => 'x'];
    $id = $this->actingAs($super)->postJson('/api/superadmin/documentos-legales', $datos)->assertCreated()->json('id');

    $this->actingAs($super)->postJson('/api/superadmin/documentos-legales', $datos)->assertUnprocessable();

    $this->actingAs($super)->deleteJson("/api/superadmin/documentos-legales/{$id}")->assertNoContent();
    expect(DocumentoLegal::find($id))->toBeNull();
});

it('sin documentos publicados el tenant no queda bloqueado', function () {
    $admin = usuarioDeTenant(Tenant::create(), 'admin');

    $this->actingAs($admin)->postJson('/api/subjects', ['tipo' => 'natural', 'nombre_canonico' => 'Ana Lopez'])->assertCreated();
});

it('con terminos vigentes sin aceptar bloquea crear personas y buscar, pero no consultar', function () {
    Bus::fake();
    $tenant = Tenant::create();
    $analista = usuarioDeTenant($tenant, 'analista');
    tenancy()->initialize($tenant);
    $subject = Subject::factory()->create();
    tenancy()->end();
    Source::factory()->create(['tipo' => 'brave', 'activo' => true]);
    publicado();

    $this->actingAs($analista)->postJson('/api/subjects', ['tipo' => 'natural', 'nombre_canonico' => 'Ana Lopez'])
        ->assertForbidden()->assertJsonPath('codigo', 'terminos_pendientes');
    $this->actingAs($analista)->postJson("/api/subjects/{$subject->id}/buscar")
        ->assertForbidden()->assertJsonPath('codigo', 'terminos_pendientes');
    $this->actingAs($analista)->postJson('/api/busquedas-tags', ['tag_ids' => [1]])->assertForbidden();

    $this->actingAs($analista)->getJson('/api/subjects')->assertOk();
    $this->actingAs($analista)->getJson("/api/subjects/{$subject->id}")->assertOk();
});

it('el admin acepta los documentos vigentes y el tenant se desbloquea; queda auditado', function () {
    $tenant = Tenant::create();
    $admin = usuarioDeTenant($tenant, 'admin');
    $terminos = publicado('terminos');
    $contrato = publicado('contrato_encargo');

    $this->actingAs($admin)->postJson("/api/documentos-legales/{$terminos->id}/aceptar")->assertOk();
    // Falta el contrato: sigue bloqueado.
    $this->actingAs($admin)->postJson('/api/subjects', ['tipo' => 'natural', 'nombre_canonico' => 'Ana Lopez'])->assertForbidden();

    $this->actingAs($admin)->withServerVariables(['REMOTE_ADDR' => '10.0.0.7'])
        ->postJson("/api/documentos-legales/{$contrato->id}/aceptar")->assertOk();
    $this->actingAs($admin)->postJson('/api/subjects', ['tipo' => 'natural', 'nombre_canonico' => 'Ana Lopez'])->assertCreated();

    tenancy()->initialize($tenant);
    $aceptacion = AceptacionDocumento::where('documento_legal_id', $contrato->id)->sole();
    tenancy()->end();
    expect($aceptacion->aceptado_por)->toBe($admin->id)
        ->and($aceptacion->ip)->toBe('10.0.0.7')
        ->and(Activity::where('subject_type', AceptacionDocumento::class)->where('tenant_id', $tenant->id)->where('event', 'created')->count())->toBe(2);
});

it('solo el admin acepta', function (string $rol) {
    $user = usuarioDeTenant(Tenant::create(), $rol);
    $doc = publicado();

    $this->actingAs($user)->postJson("/api/documentos-legales/{$doc->id}/aceptar")->assertForbidden();
})->with(['oficial_cumplimiento', 'analista', 'lectura']);

it('no se acepta un borrador, una version anterior ni dos veces la misma', function () {
    $admin = usuarioDeTenant(Tenant::create(), 'admin');
    $viejo = publicado();
    $vigente = publicado();
    $borrador = $this->actingAs(superadmin())->postJson('/api/superadmin/documentos-legales', [
        'tipo' => 'terminos', 'titulo' => 'x', 'contenido' => 'x',
    ])->json('id');

    $this->actingAs($admin)->postJson("/api/documentos-legales/{$viejo->id}/aceptar")->assertUnprocessable();
    $this->actingAs($admin)->postJson("/api/documentos-legales/{$borrador}/aceptar")->assertUnprocessable();
    $this->actingAs($admin)->postJson("/api/documentos-legales/{$vigente->id}/aceptar")->assertOk();
    $this->actingAs($admin)->postJson("/api/documentos-legales/{$vigente->id}/aceptar")->assertUnprocessable();
});

it('publicar una version nueva vuelve a exigir la aceptacion', function () {
    $admin = usuarioDeTenant(Tenant::create(), 'admin');
    $v1 = publicado();
    $this->actingAs($admin)->postJson("/api/documentos-legales/{$v1->id}/aceptar")->assertOk();

    publicado();

    $this->actingAs($admin)->postJson('/api/subjects', ['tipo' => 'natural', 'nombre_canonico' => 'Ana Lopez'])->assertForbidden();
});

it('cualquier usuario del tenant ve los documentos vigentes con la aceptacion de SU tenant', function () {
    $tenant = Tenant::create();
    $otro = Tenant::create();
    $doc = publicado();
    $this->actingAs(usuarioDeTenant($otro, 'admin'))->postJson("/api/documentos-legales/{$doc->id}/aceptar")->assertOk();
    $lectura = usuarioDeTenant($tenant, 'lectura');

    $respuesta = $this->actingAs($lectura)->getJson('/api/documentos-legales')->assertOk();

    expect($respuesta->json('0.id'))->toBe($doc->id)
        ->and($respuesta->json('0.contenido'))->toBe('Texto de los terminos')
        ->and($respuesta->json('0.aceptacion'))->toBeNull();
    $this->actingAs($lectura)->getJson('/api/user')->assertJsonPath('terminos_pendientes', true);
});

it('el superadmin ve en cada tenant si tiene los documentos al dia', function () {
    $alDia = Tenant::create();
    $pendiente = Tenant::create();
    $doc = publicado();
    $this->actingAs(usuarioDeTenant($alDia, 'admin'))->postJson("/api/documentos-legales/{$doc->id}/aceptar")->assertOk();

    $tenants = collect($this->actingAs(superadmin())->getJson('/api/superadmin/tenants')->assertOk()->json());

    expect($tenants->firstWhere('id', $alDia->id)['documentos_al_dia'])->toBeTrue()
        ->and($tenants->firstWhere('id', $pendiente->id)['documentos_al_dia'])->toBeFalse();
});

it('un error inesperado al aceptar no se disfraza de 422', function () {
    $admin = usuarioDeTenant(Tenant::create(), 'admin');
    $doc = publicado();
    $this->mock(App\Actions\ProteccionDatos\AceptarDocumentoLegal::class)
        ->shouldReceive('handle')->andThrow(new RuntimeException('SQLSTATE[HY000]: Lock wait timeout'));

    $this->actingAs($admin)->postJson("/api/documentos-legales/{$doc->id}/aceptar")->assertStatus(500);
});
