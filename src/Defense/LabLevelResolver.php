<?php

declare(strict_types=1);

namespace App\Defense;

use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Resuelve el nivel de defensa efectivo en RUNTIME.
 *
 * Orden de precedencia:
 *   1. Cabecera X-Lab-Level de la peticion en curso (funcionalidad SOLO-lab,
 *      pensada para que el harness recorra los 4 niveles en una sola pasada
 *      sin reiniciar el contenedor).
 *   2. Variable de entorno LAB_LEVEL.
 *
 * El nivel nunca se hornea en el contenedor compilado de Symfony (ver ADR 05):
 * la resolucion vive aqui, en un servicio de runtime.
 *
 * Politica de validacion (ver Tarea D / fallo fuerte): un nivel fuera de rango
 * (< 0, > 3) o no numerico -> HTTP 400, NUNCA un clamp silencioso. Un clamp
 * convertiria un off-by-one del harness (mandar 4) en "medi el nivel 4" cuando
 * en realidad seria el 3: comportamiento incorrecto sin ruido, justo lo que el
 * laboratorio no puede permitirse porque corrompe la tabla de metricas. La
 * regla aplica igual a la cabecera y a LAB_LEVEL.
 */
final class LabLevelResolver
{
    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly string $envLevel,
    ) {
    }

    public function resolve(): DefenseLevel
    {
        $request = $this->requestStack->getCurrentRequest();

        if (null !== $request && $request->headers->has('X-Lab-Level')) {
            return $this->parse(
                $request->headers->get('X-Lab-Level'),
                'la cabecera X-Lab-Level',
            );
        }

        return $this->parse($this->envLevel, 'la variable de entorno LAB_LEVEL');
    }

    /**
     * Parsea y valida un nivel. Falla fuerte (400) ante cualquier valor que no
     * sea un entero exacto dentro del rango de niveles definidos.
     */
    private function parse(?string $raw, string $source): DefenseLevel
    {
        $value = null === $raw ? '' : trim($raw);

        if (1 !== preg_match('/^\d+$/', $value)) {
            throw new BadRequestHttpException(sprintf(
                'Nivel de defensa invalido en %s: "%s". Debe ser un entero entre 0 y 3.',
                $source,
                (string) $raw,
            ));
        }

        $level = DefenseLevel::tryFrom((int) $value);

        if (null === $level) {
            throw new BadRequestHttpException(sprintf(
                'Nivel de defensa fuera de rango en %s: "%s". Los niveles validos son 0, 1, 2 o 3.',
                $source,
                $value,
            ));
        }

        return $level;
    }
}
