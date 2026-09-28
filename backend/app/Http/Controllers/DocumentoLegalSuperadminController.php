<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\ProteccionDatos\PublicarDocumentoLegal;
use App\Models\DocumentoLegal;
use App\Services\ProteccionDatos\SerializadorDocumentoLegal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use RuntimeException;
use Stancl\Tenancy\Database\Models\Tenant;

/**
 * Redaccion y publicacion de terminos y contrato de encargo por el
 * superadmin (seccion 3.9, punto 1 - decision del usuario 2026-09-28).
 * Un borrador se edita o descarta; una version publicada es inmutable.
 */
class DocumentoLegalSuperadminController extends Controller
{
    public function __construct(private readonly SerializadorDocumentoLegal $serializador) {}

    public function index(): JsonResponse
    {
        Gate::authorize('gestionar', Tenant::class);

        $vigentes = DocumentoLegal::vigentes()->modelKeys();

        return response()->json(
            DocumentoLegal::query()->with('publicadoPor')->withCount('aceptaciones')
                ->orderBy('tipo')->orderByRaw('version is null desc')->orderByDesc('version')->get()
                ->map(fn (DocumentoLegal $d) => $this->serializador->paraSuperadmin($d, $vigentes))
        );
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('gestionar', Tenant::class);

        $validated = $request->validate([
            'tipo' => ['required', Rule::in(DocumentoLegal::TIPOS)],
            ...$this->reglasTexto(),
        ]);

        abort_if(
            DocumentoLegal::query()->where('tipo', $validated['tipo'])->whereNull('publicado_en')->exists(),
            422,
            'Ya hay un borrador de este documento: edítalo o descártalo primero.',
        );

        $documento = DocumentoLegal::create([...$validated, 'creado_por' => $request->user()->id]);

        return response()->json($this->serializador->paraSuperadmin($documento, []), 201);
    }

    public function update(Request $request, DocumentoLegal $documento): JsonResponse
    {
        Gate::authorize('gestionar', Tenant::class);
        $this->exigirBorrador($documento);

        $documento->update($request->validate($this->reglasTexto()));

        return response()->json($this->serializador->paraSuperadmin($documento, []));
    }

    public function destroy(DocumentoLegal $documento): Response
    {
        Gate::authorize('gestionar', Tenant::class);
        $this->exigirBorrador($documento);

        $documento->delete();

        return response()->noContent();
    }

    public function publicar(Request $request, DocumentoLegal $documento, PublicarDocumentoLegal $action): JsonResponse
    {
        Gate::authorize('gestionar', Tenant::class);

        try {
            $documento = $action->handle($documento, $request->user());
        } catch (RuntimeException $e) {
            return response()->json(['mensaje' => $e->getMessage()], 422);
        }

        return response()->json($this->serializador->paraSuperadmin($documento->load('publicadoPor'), [$documento->id]));
    }

    private function exigirBorrador(DocumentoLegal $documento): void
    {
        abort_unless($documento->esBorrador(), 422, 'Una versión publicada no se modifica: crea un borrador nuevo.');
    }

    /** @return array<string, list<string>> */
    private function reglasTexto(): array
    {
        return [
            'titulo' => ['required', 'string', 'max:255'],
            'contenido' => ['required', 'string', 'max:200000'],
        ];
    }
}
