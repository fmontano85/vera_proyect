<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\ProteccionDatos\AceptarDocumentoLegal;
use App\Models\AceptacionDocumento;
use App\Models\DocumentoLegal;
use App\Services\ProteccionDatos\SerializadorDocumentoLegal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use App\Exceptions\OperacionNoPermitida;

/**
 * Documentos legales vigentes vistos desde un tenant (seccion 3.9, punto
 * 1): cualquier usuario los lee; solo el admin los acepta.
 */
class DocumentoLegalController extends Controller
{
    public function index(SerializadorDocumentoLegal $serializador): JsonResponse
    {
        $vigentes = DocumentoLegal::vigentes();
        // Scope de tenant: solo las aceptaciones de ESTE tenant.
        $aceptaciones = AceptacionDocumento::query()->with('aceptadoPor')
            ->whereIn('documento_legal_id', $vigentes->modelKeys())->get()->keyBy('documento_legal_id');

        return response()->json(
            $vigentes->map(fn (DocumentoLegal $d) => $serializador->paraTenant($d, $aceptaciones->get($d->id)))
        );
    }

    public function aceptar(Request $request, DocumentoLegal $documento, AceptarDocumentoLegal $action, SerializadorDocumentoLegal $serializador): JsonResponse
    {
        Gate::authorize('aceptar-documentos-legales');

        try {
            $aceptacion = $action->handle($documento, $request->user(), $request->ip());
        } catch (OperacionNoPermitida $e) {
            return response()->json(['mensaje' => $e->getMessage()], 422);
        }

        return response()->json($serializador->paraTenant($documento, $aceptacion->load('aceptadoPor')));
    }
}
