<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Debe coincidir exactamente con el enum de la columna mentions.origen
 * (seccion 3.7 del CLAUDE.md raiz, migracion
 * add_search_result_and_origen_to_mentions_table).
 */
enum OrigenMention: string
{
    case Automatico = 'automatico';
    case Manual = 'manual';
}
