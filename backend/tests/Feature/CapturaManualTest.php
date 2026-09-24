<?php

declare(strict_types=1);

use App\Enums\EstadoSearchResult;
use App\Enums\GapMotivo;
use App\Models\Mention;
use App\Models\MentionMatch;
use App\Models\SearchResult;
use App\Models\Subject;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Stancl\Tenancy\Database\Models\Tenant;

function crearUsuarioCapturaConRol(Tenant $tenant, string $rol): User
{
    $user = User::factory()->create();
    $user->forceFill(['tenant_id' => $tenant->id])->save();
    $user->assignRole($rol);

    return $user;
}

function resultadoEnGap(Tenant $tenant): SearchResult
{
    tenancy()->initialize($tenant);
    $subject = Subject::factory()->create();
    $resultado = SearchResult::factory()->for($subject, 'subject')->create();
    $resultado->forceFill(['estado' => EstadoSearchResult::Gap, 'gap_motivo' => GapMotivo::Http403])->save();
    tenancy()->end();

    return $resultado;
}

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    Storage::fake(config('vera.evidencia_manual_disk'));
});

it('oficial_cumplimiento puede capturar manualmente un resultado en gap con PDF y queda ya resuelto', function () {
    $tenant = Tenant::create();
    $user = crearUsuarioCapturaConRol($tenant, 'oficial_cumplimiento');
    $resultado = resultadoEnGap($tenant);

    $respuesta = $this->actingAs($user)->post("/api/resultados/{$resultado->id}/captura-manual", [
        'nombre_como_aparece' => 'Juan Perez',
        'rol' => 'condenado',
        'delitos' => ['homicidio simple'],
        'fecha_hecho' => '2026-01-15',
        'resumen' => 'Condenado por homicidio.',
        'estado_resolucion' => 'confirmado',
        'pdf' => UploadedFile::fake()->create('evidencia.pdf', 100, 'application/pdf'),
    ]);

    $respuesta->assertCreated();

    tenancy()->initialize($tenant);
    $mention = Mention::sole();
    expect($mention->nombre_extraido)->toBe('Juan Perez')
        ->and($mention->origen->value)->toBe('manual')
        ->and($mention->creado_por)->toBe($user->id)
        ->and($mention->confianza)->toBeNull();

    $match = MentionMatch::sole();
    expect($match->estado)->toBe('confirmado')
        ->and($match->resuelto_por)->toBe($user->id)
        ->and($match->resuelto_en)->not->toBeNull()
        ->and($match->score_meilisearch)->toBeNull();

    $resultado->refresh();
    expect($resultado->estado)->toBe(EstadoSearchResult::Extraido)
        ->and($resultado->evidencia_manual_path)->not->toBeNull();
    tenancy()->end();

    Storage::disk(config('vera.evidencia_manual_disk'))->assertExists($resultado->evidencia_manual_path);
});

it('analista no puede capturar manualmente (solo quien ya resuelve en firme)', function () {
    $tenant = Tenant::create();
    $user = crearUsuarioCapturaConRol($tenant, 'analista');
    $resultado = resultadoEnGap($tenant);

    $this->actingAs($user)->post("/api/resultados/{$resultado->id}/captura-manual", [
        'nombre_como_aparece' => 'Juan Perez',
        'rol' => 'condenado',
        'delitos' => ['homicidio simple'],
        'estado_resolucion' => 'confirmado',
        'pdf' => UploadedFile::fake()->create('evidencia.pdf', 100, 'application/pdf'),
    ])->assertForbidden();
});

it('responde 422 si el resultado no esta en gap', function () {
    $tenant = Tenant::create();
    $user = crearUsuarioCapturaConRol($tenant, 'admin');

    tenancy()->initialize($tenant);
    $subject = Subject::factory()->create();
    $resultado = SearchResult::factory()->for($subject, 'subject')->create();
    tenancy()->end();

    $this->actingAs($user)->post("/api/resultados/{$resultado->id}/captura-manual", [
        'nombre_como_aparece' => 'Juan Perez',
        'rol' => 'condenado',
        'delitos' => ['homicidio simple'],
        'estado_resolucion' => 'confirmado',
        'pdf' => UploadedFile::fake()->create('evidencia.pdf', 100, 'application/pdf'),
    ])->assertStatus(422);
});

it('rechaza la captura manual sin PDF o con un archivo que no es PDF', function () {
    $tenant = Tenant::create();
    $user = crearUsuarioCapturaConRol($tenant, 'admin');
    $resultado = resultadoEnGap($tenant);

    $this->actingAs($user)->postJson("/api/resultados/{$resultado->id}/captura-manual", [
        'nombre_como_aparece' => 'Juan Perez',
        'rol' => 'condenado',
        'delitos' => ['homicidio simple'],
        'estado_resolucion' => 'confirmado',
        // sin 'pdf'
    ])->assertStatus(422)->assertJsonValidationErrors('pdf');

    $this->actingAs($user)->post("/api/resultados/{$resultado->id}/captura-manual", [
        'nombre_como_aparece' => 'Juan Perez',
        'rol' => 'condenado',
        'delitos' => ['homicidio simple'],
        'estado_resolucion' => 'confirmado',
        'pdf' => UploadedFile::fake()->create('evidencia.exe', 100, 'application/x-msdownload'),
    ])->assertStatus(422)->assertJsonValidationErrors('pdf');
});

it('exige al menos un delito', function () {
    $tenant = Tenant::create();
    $user = crearUsuarioCapturaConRol($tenant, 'admin');
    $resultado = resultadoEnGap($tenant);

    $this->actingAs($user)->postJson("/api/resultados/{$resultado->id}/captura-manual", [
        'nombre_como_aparece' => 'Juan Perez',
        'rol' => 'condenado',
        'delitos' => [],
        'estado_resolucion' => 'confirmado',
        'pdf' => UploadedFile::fake()->create('evidencia.pdf', 100, 'application/pdf'),
    ])->assertStatus(422)->assertJsonValidationErrors('delitos');
});
