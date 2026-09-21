<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Debe coincidir exactamente con el enum de la columna mentions.rol
 * (migracion create_mentions_table). Usarlo como tipo en
 * PersonaExtraidaData hace que spatie/laravel-data rechace cualquier
 * valor de "rol" que Claude devuelva fuera de esta lista, ANTES de que
 * llegue a un Mention::create() y truene contra la restriccion de la BD.
 */
enum RolMencion: string
{
    case Imputado = 'imputado';
    case Condenado = 'condenado';
    case Victima = 'victima';
    case Testigo = 'testigo';
    case Otro = 'otro';
}
