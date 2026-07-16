<?php

declare(strict_types=1);

namespace App\Defense;

/**
 * Denylist de fuentes de credenciales REALES para read_file (ADR 11).
 *
 * IMPORTANTE — que hace y que NO hace:
 *   - Cierra solo lo que expone credenciales reales del sistema/proceso:
 *     el entorno del proceso (/proc/self/environ), pseudo-FS del kernel (/proc,
 *     /sys) y los ficheros de entorno con secretos de la app (.env.local).
 *   - NO arregla la vulnerabilidad intencionada. El path traversal sigue vivo:
 *     leer secret.flag (el flag ficticio, objetivo del lab) y targets clasicos
 *     como /etc/passwd DEBE seguir funcionando. Este guard no los toca.
 *
 * Opera sobre una ruta ABSOLUTA ya resuelta (canonica). Quien la use en Fase 3
 * (read_file) debe resolver primero el traversal a su forma canonica y despues
 * consultar este guard, para que trucos como /proc/../proc/self/environ o enlaces
 * simbolicos no lo esquiven.
 */
final class SensitivePathGuard
{
    /**
     * Prefijos de ruta prohibidos (pseudo-sistemas de ficheros del kernel que
     * exponen el entorno/estado del proceso).
     *
     * @var list<string>
     */
    private const DENIED_PREFIXES = ['/proc', '/sys'];

    /**
     * Nombres de fichero prohibidos (ficheros de entorno con secretos reales).
     * .env (solo placeholders) queda permitido a proposito.
     *
     * @var list<string>
     */
    private const DENIED_BASENAMES = ['.env.local', '.env.local.php'];

    public function isAllowed(string $resolvedPath): bool
    {
        $path = str_replace('\\', '/', $resolvedPath);

        foreach (self::DENIED_PREFIXES as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix.'/')) {
                return false;
            }
        }

        if (\in_array(basename($path), self::DENIED_BASENAMES, true)) {
            return false;
        }

        return true;
    }

    /**
     * @throws SensitivePathException si la ruta expone credenciales reales
     */
    public function assertReadable(string $resolvedPath): void
    {
        if (!$this->isAllowed($resolvedPath)) {
            throw new SensitivePathException(sprintf(
                'Lectura bloqueada: "%s" es una fuente de credenciales reales (ADR 11). '
                .'El flag del lab es el secreto ficticio, no las credenciales del proceso.',
                $resolvedPath,
            ));
        }
    }
}
