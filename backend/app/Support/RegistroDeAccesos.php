<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Database\Eloquent\Model;

/**
 * Registro de accesos en la bitacora (seccion 3.9, punto 7): acciones sin
 * cambio de modelo que LogsActivity no ve (consultas, descargas, login).
 * No se registra abrir la ficha de una persona (decision 2026-09-28, por
 * volumen). El tenant lo asigna App\Models\Activity al crear el registro.
 */
final class RegistroDeAccesos
{
    /**
     * @param  array<string, mixed>  $propiedades  nunca datos personales de mas: se guardan tal cual
     */
    public static function registrar(string $evento, string $descripcion, ?Model $sobre = null, array $propiedades = []): void
    {
        $registro = activity()->event($evento)->withProperties($propiedades);

        if ($sobre !== null) {
            $registro->performedOn($sobre);
        }

        if (($usuario = auth()->user()) !== null) {
            $registro->causedBy($usuario);
        }

        $registro->log($descripcion);
    }
}
