<?php

declare(strict_types=1);

namespace App\Actions\ProteccionDatos;

use App\Models\AceptacionDocumento;
use App\Models\DocumentoLegal;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use App\Exceptions\OperacionNoPermitida;

/**
 * El admin del tenant acepta la version vigente de un documento (seccion
 * 3.9, punto 1). Solo la vigente: un borrador o una version anterior no
 * se aceptan. El tenant lo asigna BelongsToTenant (ruta de tenant).
 */
class AceptarDocumentoLegal
{
    public function handle(DocumentoLegal $documento, User $admin, ?string $ip): AceptacionDocumento
    {
        if (! DocumentoLegal::vigentes()->contains('id', $documento->id)) {
            throw new OperacionNoPermitida('Solo se puede aceptar la versión vigente de un documento publicado.');
        }

        if (AceptacionDocumento::query()->where('documento_legal_id', $documento->id)->exists()) {
            throw new OperacionNoPermitida('Tu organización ya aceptó esta versión.');
        }

        try {
            return AceptacionDocumento::create([
                'documento_legal_id' => $documento->id,
                'aceptado_por' => $admin->id,
                'aceptado_en' => now(),
                'ip' => $ip,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Doble clic simultaneo: la otra peticion ya la registro.
            throw new OperacionNoPermitida('Tu organización ya aceptó esta versión.');
        }
    }
}
