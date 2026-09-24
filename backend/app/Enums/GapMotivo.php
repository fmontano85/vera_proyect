<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Debe coincidir exactamente con el enum de la columna
 * search_results.gap_motivo (seccion 3.7 del CLAUDE.md raiz). Un GAP no
 * es un fallo de job - es un resultado legitimo que no se pudo procesar
 * automatico y admite captura manual.
 */
enum GapMotivo: string
{
    case Http403 = 'http_403';
    case HttpError = 'http_error';
    case Timeout = 'timeout';
    case SinContenido = 'sin_contenido';
    case FueraDeVentana = 'fuera_de_ventana';
    case NoHtml = 'no_html';
}
