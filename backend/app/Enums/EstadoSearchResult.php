<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Debe coincidir exactamente con el enum de la columna search_results.estado
 * (seccion 3.7 del CLAUDE.md raiz).
 */
enum EstadoSearchResult: string
{
    case Nuevo = 'nuevo';
    case Procesando = 'procesando';
    case Extraido = 'extraido';
    case SinMenciones = 'sin_menciones';
    case Gap = 'gap';
    case Descartado = 'descartado';
}
