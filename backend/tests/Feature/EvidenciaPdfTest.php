<?php

declare(strict_types=1);

use App\Enums\EstadoSearchResult;
use App\Models\Activity;
use App\Models\Article;
use App\Models\SearchResult;
use App\Models\Subject;
use App\Services\Evidence\DocumentoEvidencia;
use App\Services\Evidence\TextoDeEvidencia;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Storage;
use Stancl\Tenancy\Database\Models\Tenant;

/**
 * PDF de evidencia bajo demanda (seccion 3.6): se arma con el snapshot
 * guardado, nunca con la URL viva, sin ejecutar scripts ni cargar
 * recursos externos, con portada de URL, fecha de captura y hash.
 */
const HTML_EVIDENCIA = '<html><head><title>Nota</title><style>p{color:red}</style>'
    .'<script>alert("xss")</script><img src="https://rastreo.example/pixel.gif"></head>'
    .'<body><h1>Capturan a Juan Perez</h1><p>La Fiscalía informó la captura.</p>'
    .'<div>Segundo párrafo &amp; más.</div></body></html>';

/** $htmlGuardado distinto de HTML_EVIDENCIA simula un snapshot alterado despues de capturarlo. */
function resultadoConSnapshot(Tenant $tenant, ?string $htmlGuardado = null, string $url = 'https://diario1.com/nota-captura'): SearchResult
{
    $hash = hash('sha256', HTML_EVIDENCIA);
    $ruta = 'articles/'.md5($url).'.html';
    Storage::put($ruta, $htmlGuardado ?? HTML_EVIDENCIA);

    tenancy()->initialize($tenant);
    $article = Article::factory()->create([
        'url' => $url,
        'titulo' => 'Capturan a Juan Perez',
        'medio' => 'diario1.com',
        'hash_contenido' => $hash,
        'evidence_path' => $ruta,
    ]);
    $resultado = SearchResult::factory()->for(Subject::factory(), 'subject')->create();
    $resultado->forceFill(['estado' => EstadoSearchResult::Extraido, 'article_id' => $article->id])->save();
    tenancy()->end();

    return $resultado;
}

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    Storage::fake();
});

it('extrae el texto del snapshot sin scripts, estilos ni recursos externos, por parrafos', function () {
    $parrafos = TextoDeEvidencia::parrafos(HTML_EVIDENCIA);

    expect($parrafos)->toBe(['Nota', 'Capturan a Juan Perez', 'La Fiscalía informó la captura.', 'Segundo párrafo & más.'])
        ->and(implode(' ', $parrafos))->not->toContain('alert')
        ->and(implode(' ', $parrafos))->not->toContain('rastreo');
});

it('toma solo el cuerpo del articulo y descarta menus, cabecera y pie del sitio', function () {
    $html = '<html><head><title>Nota</title></head><body>'
        .'<header><nav><ul><li>Portada</li><li>Deportes</li></ul></nav></header>'
        .'<main><article><h1>Capturan a Juan Perez</h1><p>Texto de la nota.</p></article>'
        .'<aside><p>Lo más leído</p></aside></main>'
        .'<footer><p>Todos los derechos reservados</p></footer></body></html>';

    expect(TextoDeEvidencia::parrafos($html))->toBe(['Capturan a Juan Perez', 'Texto de la nota.']);
});

it('sin article ni main usa toda la pagina, sin la navegacion', function () {
    $html = '<body><nav><a>Portada</a></nav><div>Unico texto.</div><footer>Pie</footer></body>';

    expect(TextoDeEvidencia::parrafos($html))->toBe(['Unico texto.']);
});

it('la portada verifica la integridad del snapshot contra el hash guardado', function () {
    $tenant = Tenant::create();
    $integro = resultadoConSnapshot($tenant);
    $alterado = resultadoConSnapshot(Tenant::create(), '<html>alterado</html>', 'https://diario1.com/otra-nota');

    tenancy()->initialize($tenant);
    $datos = app(DocumentoEvidencia::class)->datos($integro->fresh('article'));
    tenancy()->end();

    expect($datos['url'])->toBe('https://diario1.com/nota-captura')
        ->and($datos['hash'])->toBe(hash('sha256', HTML_EVIDENCIA))
        ->and($datos['integridad_verificada'])->toBeTrue()
        ->and(app(DocumentoEvidencia::class)->datos($alterado->fresh('article'))['integridad_verificada'])->toBeFalse();
});

it('descarga el PDF de la evidencia como adjunto y lo registra en la bitacora', function () {
    $tenant = Tenant::create();
    $resultado = resultadoConSnapshot($tenant);

    $respuesta = $this->actingAs(usuarioDeTenant($tenant, 'lectura'))
        ->get("/api/resultados/{$resultado->id}/evidencia/pdf")->assertOk();

    expect($respuesta->headers->get('content-type'))->toBe('application/pdf')
        ->and($respuesta->headers->get('content-disposition'))->toContain("evidencia-{$resultado->id}.pdf")
        ->and(substr((string) $respuesta->getContent(), 0, 4))->toBe('%PDF')
        ->and(Activity::where('event', 'evidencia_descargada')->sole()->getProperty('tipo'))->toBe('pdf');
});

it('responde 404 si el resultado no tiene snapshot guardado', function () {
    $tenant = Tenant::create();
    tenancy()->initialize($tenant);
    $resultado = SearchResult::factory()->for(Subject::factory(), 'subject')->create();
    tenancy()->end();

    $this->actingAs(usuarioDeTenant($tenant, 'lectura'))
        ->get("/api/resultados/{$resultado->id}/evidencia/pdf")->assertNotFound();
});

it('no da el PDF de evidencia de otro tenant', function () {
    $resultado = resultadoConSnapshot(Tenant::create());

    $this->actingAs(usuarioDeTenant(Tenant::create(), 'admin'))
        ->get("/api/resultados/{$resultado->id}/evidencia/pdf")->assertNotFound();
});

// ── Correcciones del /code-review high (2026-09-28) ─────────────────────

it('conserva el texto de paginas en ISO-8859-1 (no descarta las lineas con tildes)', function () {
    $latin1 = mb_convert_encoding('<html><head><meta charset="iso-8859-1"></head><body><p>La Fiscalía informó la captura.</p><p>Año de la niña</p></body></html>', 'ISO-8859-1', 'UTF-8');

    expect(TextoDeEvidencia::parrafos($latin1))->toBe(['La Fiscalía informó la captura.', 'Año de la niña']);
});

it('toma el articulo principal, no las notas relacionadas, y conserva su titular', function () {
    $html = '<body><article><header><h1>Capturan a Juan Perez</h1><p>Por Redacción</p></header>'
        .'<p>La Fiscalía capturó a Juan Perez por estafa agravada en San Salvador ayer por la tarde.</p></article>'
        .'<section><h2>Te puede interesar</h2><article><h3>Condenan a Pedro Gomez</h3></article>'
        .'<article><h3>Liberan a Maria Diaz</h3></article></section></body>';

    $parrafos = TextoDeEvidencia::parrafos($html);

    expect($parrafos)->toContain('Capturan a Juan Perez')
        ->and(implode(' ', $parrafos))->not->toContain('Pedro Gomez')
        ->and(implode(' ', $parrafos))->not->toContain('Maria Diaz');
});

it('recorta un snapshot enorme y lo indica en el PDF', function () {
    $html = '<body>'.str_repeat('<p>Parrafo de relleno con algo de texto.</p>', 5000).'</body>';

    $texto = TextoDeEvidencia::extraer($html);

    expect(count($texto['parrafos']))->toBe(TextoDeEvidencia::MAX_PARRAFOS)
        ->and($texto['recortado'])->toBeTrue();
});
