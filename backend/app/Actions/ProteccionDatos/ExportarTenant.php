<?php

declare(strict_types=1);

namespace App\Actions\ProteccionDatos;

use App\Models\AceptacionDocumento;
use App\Models\Activity;
use App\Models\FrecuenciaSeguimiento;
use App\Models\SearchResult;
use App\Models\Subject;
use App\Models\User;
use App\Services\Bitacora\SerializadorBitacora;
use App\Support\ConfiguracionTenant;
use RuntimeException;
use Stancl\Tenancy\Database\Models\Tenant;
use ZipArchive;

/**
 * Exportacion completa de un tenant para devolverle sus datos al cliente
 * al terminar el contrato (seccion 3.9, punto 6). Es el unico caso en que
 * el superadmin maneja datos personales de las personas vigiladas: la
 * devolucion la exige el contrato de encargo. Queda en la bitacora.
 *
 * ZIP: tenant.json (organizacion, usuarios, configuracion, aceptaciones),
 * personas/{id}/ (lo mismo que la exportacion individual),
 * busquedas-por-tags.json (+ su evidencia manual) y bitacora.json.
 */
class ExportarTenant
{
    public function __construct(
        private readonly ExportarSubject $exportarSubject,
        private readonly SerializadorBitacora $serializadorBitacora,
    ) {}

    public function handle(Tenant $tenant, User $superadmin): string
    {
        $ruta = tempnam(sys_get_temp_dir(), 'vera-tenant-');
        $zip = new ZipArchive;

        if ($zip->open($ruta, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('No se pudo crear el archivo de exportación.');
        }

        $tenant->run(function () use ($tenant, $superadmin, $zip) {
            $zip->addFromString('tenant.json', ExportarSubject::json($this->general($tenant)));

            Subject::query()->orderBy('id')->each(
                fn (Subject $s) => $this->exportarSubject->agregarAlZip($zip, $s, $superadmin, "personas/{$s->id}/")
            );

            $porTags = SearchResult::query()->whereNull('subject_id')->with('searchRun')->orderBy('id')->get();
            $zip->addFromString('busquedas-por-tags.json', ExportarSubject::json($porTags->map(fn (SearchResult $r) => [
                'id' => $r->id,
                'tags' => $r->searchRun?->tags,
                'url' => $r->url,
                'titulo' => $r->titulo,
                'snippet' => $r->snippet,
                'medio' => $r->medio,
                'estado' => $r->estado,
                'encontrado_en' => $r->created_at,
            ])->all()));
            ExportarSubject::agregarEvidencias($zip, $porTags, 'busquedas-por-tags/');
        });

        $bitacora = Activity::query()->where('tenant_id', $tenant->id)->with('causer')->orderBy('id')->get()
            ->map(fn (Activity $a) => $this->serializadorBitacora->completo($a))->all();
        $zip->addFromString('bitacora.json', ExportarSubject::json($bitacora));
        $zip->close();

        activity()->performedOn($tenant)->causedBy($superadmin)->event('tenant_exportado')
            ->log('Exportación completa del tenant');

        return $ruta;
    }

    /** @return array<string, mixed> */
    private function general(Tenant $tenant): array
    {
        return [
            'exportado_en' => now()->toIso8601String(),
            'tenant' => ['id' => $tenant->id, 'name' => $tenant->name, 'creado_en' => $tenant->created_at?->toIso8601String()],
            'configuracion' => [
                'retencion_anios' => ConfiguracionTenant::retencionAnios($tenant),
                'depuracion_habilitada' => ConfiguracionTenant::depuracionHabilitada($tenant),
                'sanciones_habilitado' => (bool) $tenant->sanciones_habilitado,
                'frecuencias_seguimiento' => FrecuenciaSeguimiento::query()->pluck('dias', 'nivel_riesgo'),
            ],
            'usuarios' => User::query()->where('tenant_id', $tenant->id)->with('roles')->orderBy('id')->get()
                ->map(fn (User $u) => [
                    'name' => $u->name, 'email' => $u->email, 'rol' => $u->getRoleNames()->first(),
                    'activo' => (bool) $u->activo, 'creado_en' => $u->created_at?->toIso8601String(),
                ])->all(),
            'aceptaciones_documentos' => AceptacionDocumento::query()->with(['documento', 'aceptadoPor'])->orderBy('id')->get()
                ->map(fn (AceptacionDocumento $a) => [
                    'documento' => $a->documento?->tipo, 'version' => $a->documento?->version,
                    'aceptado_por' => $a->aceptadoPor?->name, 'aceptado_en' => $a->aceptado_en->toIso8601String(), 'ip' => $a->ip,
                ])->all(),
        ];
    }
}
