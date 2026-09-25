<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Tipos de alerta (seccion 3.3). Por ahora solo la agenda de seguimiento
 * de la seccion 3.8 - no existe monitoreo automatico que genere alertas
 * de coincidencias nuevas (decision 2026-09-25).
 */
enum TipoAlerta: string
{
    case SeguimientoPendiente = 'seguimiento_pendiente';
}
