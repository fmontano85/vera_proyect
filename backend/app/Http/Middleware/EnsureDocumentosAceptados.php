<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\ProteccionDatos\EstadoDocumentosLegales;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sin aceptar los terminos y el contrato vigentes, el tenant no carga
 * personas ni busca (seccion 3.9, punto 1). Consultar lo existente sigue
 * permitido. Va despues del middleware 'tenant' (necesita tenancy).
 */
class EnsureDocumentosAceptados
{
    public function __construct(private readonly EstadoDocumentosLegales $estado) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->estado->alDia((string) tenant()->getTenantKey())) {
            return response()->json([
                'mensaje' => 'El administrador de tu organización debe aceptar los términos y el contrato vigentes antes de cargar personas o hacer búsquedas.',
                'codigo' => 'terminos_pendientes',
            ], 403);
        }

        return $next($request);
    }
}
