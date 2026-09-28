<?php

declare(strict_types=1);

namespace App\Services\ProteccionDatos;

use App\Models\DocumentoLegal;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Que documentos vigentes le faltan aceptar a un tenant (seccion 3.9,
 * punto 1). Sin ningun documento publicado no hay nada que aceptar y el
 * tenant no se bloquea: en produccion hay que publicar los terminos antes
 * del primer cliente.
 *
 * Consulta aceptaciones_documentos por tenant_id explicito (no por el
 * scope): se usa tambien fuera de tenancy (panel de superadmin, /api/user).
 */
class EstadoDocumentosLegales
{
    /** Cacheado por instancia: el listado de tenants del superadmin consulta uno por tenant. */
    private ?Collection $vigentes = null;

    /** @return Collection<int, DocumentoLegal> */
    public function pendientesDe(string $tenantId): Collection
    {
        $vigentes = $this->vigentes ??= DocumentoLegal::vigentes();

        $aceptados = DB::table('aceptaciones_documentos')
            ->where('tenant_id', $tenantId)
            ->whereIn('documento_legal_id', $vigentes->modelKeys())
            ->pluck('documento_legal_id')
            ->all();

        return $vigentes->reject(fn (DocumentoLegal $d) => in_array($d->id, $aceptados, true))->values();
    }

    public function alDia(string $tenantId): bool
    {
        return $this->pendientesDe($tenantId)->isEmpty();
    }
}
