<?php

declare(strict_types=1);

use App\Enums\EstadoSearchResult;
use App\Models\Article;
use App\Models\SearchResult;
use App\Models\Subject;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Storage;
use Stancl\Tenancy\Database\Models\Tenant;

function usuarioEvidencia(Tenant $tenant, string $rol): User
{
    $user = User::factory()->create();
    $user->forceFill(['tenant_id' => $tenant->id])->save();
    $user->assignRole($rol);

    return $user;
}

function resultadoConEvidencia(Tenant $tenant): SearchResult
{
    tenancy()->initialize($tenant);
    $subject = Subject::factory()->create();
    $article = Article::factory()->create(['evidence_path' => 'articles/abc.html']);
    $resultado = SearchResult::factory()->for($subject, 'subject')->create();
    $resultado->forceFill([
        'estado' => EstadoSearchResult::Extraido,
        'article_id' => $article->id,
        'evidencia_manual_path' => "tenants/{$tenant->id}/evidencia-manual/{$resultado->id}.pdf",
    ])->save();
    tenancy()->end();

    Storage::put('articles/abc.html', '<html><script>alert(1)</script>contenido</html>');
    Storage::disk(config('vera.evidencia_manual_disk'))->put($resultado->evidencia_manual_path, '%PDF-1.4 fake');

    return $resultado;
}

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    Storage::fake();
    Storage::fake(config('vera.evidencia_manual_disk'));
});

it('descarga el snapshot HTML como adjunto, nunca renderizado inline', function () {
    $tenant = Tenant::create();
    $user = usuarioEvidencia($tenant, 'lectura');
    $resultado = resultadoConEvidencia($tenant);

    $respuesta = $this->actingAs($user)->get("/api/resultados/{$resultado->id}/evidencia/snapshot");

    $respuesta->assertOk();
    expect($respuesta->headers->get('content-disposition'))->toContain('attachment');
    expect($respuesta->headers->get('x-content-type-options'))->toBe('nosniff');
    expect($respuesta->headers->get('content-type'))->toStartWith('text/plain');
    expect($respuesta->streamedContent())->toContain('contenido');
});

it('expone Content-Disposition al frontend via CORS - sin esto el navegador guarda el archivo sin nombre', function () {
    $tenant = Tenant::create();
    $user = usuarioEvidencia($tenant, 'lectura');
    $resultado = resultadoConEvidencia($tenant);

    $respuesta = $this->actingAs($user)
        ->withHeaders(['Origin' => config('cors.allowed_origins')[0]])
        ->get("/api/resultados/{$resultado->id}/evidencia/snapshot");

    $respuesta->assertOk();
    expect($respuesta->headers->get('access-control-expose-headers'))->toContain('Content-Disposition');
});

it('descarga el PDF de captura manual', function () {
    $tenant = Tenant::create();
    $user = usuarioEvidencia($tenant, 'analista');
    $resultado = resultadoConEvidencia($tenant);

    $respuesta = $this->actingAs($user)->get("/api/resultados/{$resultado->id}/evidencia/manual");

    $respuesta->assertOk();
    expect($respuesta->headers->get('content-type'))->toStartWith('application/pdf');
    expect($respuesta->headers->get('content-disposition'))->toContain('attachment');
});

it('404 si el resultado no tiene esa evidencia', function () {
    $tenant = Tenant::create();
    $user = usuarioEvidencia($tenant, 'admin');
    tenancy()->initialize($tenant);
    $resultado = SearchResult::factory()->for(Subject::factory()->create(), 'subject')->create();
    tenancy()->end();

    $this->actingAs($user)->get("/api/resultados/{$resultado->id}/evidencia/snapshot")->assertNotFound();
    $this->actingAs($user)->get("/api/resultados/{$resultado->id}/evidencia/manual")->assertNotFound();
});

it('404 con un tipo de evidencia que no existe', function () {
    $tenant = Tenant::create();
    $user = usuarioEvidencia($tenant, 'admin');
    $resultado = resultadoConEvidencia($tenant);

    $this->actingAs($user)->get("/api/resultados/{$resultado->id}/evidencia/otro")->assertNotFound();
});

it('un usuario de otro tenant no puede descargar la evidencia', function () {
    $tenantA = Tenant::create();
    $tenantB = Tenant::create();
    $intruso = usuarioEvidencia($tenantB, 'admin');
    $resultado = resultadoConEvidencia($tenantA);

    $this->actingAs($intruso)->get("/api/resultados/{$resultado->id}/evidencia/snapshot")->assertNotFound();
    $this->actingAs($intruso)->get("/api/resultados/{$resultado->id}/evidencia/manual")->assertNotFound();
});

it('superadmin recibe 403', function () {
    $tenant = Tenant::create();
    $resultado = resultadoConEvidencia($tenant);
    $super = User::factory()->create();
    $super->assignRole('superadmin');

    $this->actingAs($super)->get("/api/resultados/{$resultado->id}/evidencia/snapshot")->assertForbidden();
});

it('exige autenticacion', function () {
    $tenant = Tenant::create();
    $resultado = resultadoConEvidencia($tenant);

    $this->getJson("/api/resultados/{$resultado->id}/evidencia/snapshot")->assertUnauthorized();
});
