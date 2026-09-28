<?php

declare(strict_types=1);

namespace App\Services\ProteccionDatos;

use App\Models\AceptacionDocumento;
use App\Models\DocumentoLegal;

/** JSON explicito de los documentos legales (seccion 3.9, punto 1). */
final class SerializadorDocumentoLegal
{
    /**
     * @param  list<int>  $idsVigentes
     * @return array<string, mixed>
     */
    public function paraSuperadmin(DocumentoLegal $d, array $idsVigentes): array
    {
        return $this->base($d) + [
            'estado' => match (true) {
                $d->esBorrador() => 'borrador',
                in_array($d->id, $idsVigentes, true) => 'vigente',
                default => 'anterior',
            },
            'publicado_por' => $d->publicadoPor?->name,
            'aceptaciones' => (int) ($d->aceptaciones_count ?? 0),
            'updated_at' => $d->updated_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public function paraTenant(DocumentoLegal $d, ?AceptacionDocumento $aceptacion): array
    {
        return $this->base($d) + [
            'aceptacion' => $aceptacion === null ? null : [
                'aceptado_por' => $aceptacion->aceptadoPor?->name,
                'aceptado_en' => $aceptacion->aceptado_en->toIso8601String(),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function base(DocumentoLegal $d): array
    {
        return [
            'id' => $d->id,
            'tipo' => $d->tipo,
            'version' => $d->version,
            'titulo' => $d->titulo,
            'contenido' => $d->contenido,
            'publicado_en' => $d->publicado_en?->toIso8601String(),
        ];
    }
}
