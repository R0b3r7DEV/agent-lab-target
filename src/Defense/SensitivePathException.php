<?php

declare(strict_types=1);

namespace App\Defense;

/**
 * Se lanza cuando read_file intenta leer una fuente de credenciales REALES
 * (p. ej. /proc/self/environ, .env.local). No es un fallo del vector didactico:
 * es el cierre de una fuga colateral (ver ADR 11).
 */
final class SensitivePathException extends \RuntimeException
{
}
