<?php

declare(strict_types=1);

namespace App\Exceptions;

use DomainException;

/**
 * Regla de negocio que impide una operacion (ej. dar de baja sin
 * exportacion previa, aceptar un documento que no es el vigente). Los
 * controladores la traducen a 422 con su mensaje.
 *
 * Es DomainException y no RuntimeException a proposito: QueryException
 * (PDOException) es RuntimeException, y atrapar RuntimeException
 * disfrazaba fallos reales de BD como errores de validacion con el SQL en
 * el mensaje (hallazgo del code-review 2026-09-28).
 */
class OperacionNoPermitida extends DomainException {}
