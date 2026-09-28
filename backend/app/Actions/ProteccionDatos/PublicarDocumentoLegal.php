<?php

declare(strict_types=1);

namespace App\Actions\ProteccionDatos;

use App\Models\DocumentoLegal;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Publica un borrador como la siguiente version de su tipo (seccion 3.9,
 * punto 1). Desde ese momento todos los tenants tienen que aceptarlo.
 * lockForUpdate sobre las versiones del tipo: dos publicaciones simultaneas
 * no pueden tomar el mismo numero (el unique(tipo, version) lo respalda).
 */
class PublicarDocumentoLegal
{
    public function handle(DocumentoLegal $documento, User $superadmin): DocumentoLegal
    {
        return DB::transaction(function () use ($documento, $superadmin) {
            $documento = DocumentoLegal::query()->lockForUpdate()->findOrFail($documento->id);

            if (! $documento->esBorrador()) {
                throw new RuntimeException('Esta versión ya está publicada.');
            }

            $ultima = DocumentoLegal::query()->where('tipo', $documento->tipo)->publicados()
                ->lockForUpdate()->max('version');

            $documento->forceFill([
                'version' => ((int) $ultima) + 1,
                'publicado_en' => now(),
                'publicado_por' => $superadmin->id,
            ])->save();

            return $documento;
        });
    }
}
