<?php

declare(strict_types=1);

namespace App\Controller;

use App\Defense\LabLevelResolver;
use App\Lab\ExfilProjector;
use App\Lab\LabResetService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Endpoints de administracion/observabilidad del laboratorio.
 *
 * Controlador delgado: toda la logica vive en servicios (aqui solo se resuelve
 * el nivel efectivo y se serializa).
 *
 * Los verbos NO son cosmeticos: son la frontera anti-SSRF (Q2). `fetch_url` es
 * deliberadamente sobre-permisiva y solo emite GET; por eso `/api/reset` es POST-only
 * (una SSRF no puede resetear la corrida a mitad de un experimento) y `/api/exfil` es
 * GET-only de solo-lectura. Esto NO arregla la vulnerabilidad intencionada (exfiltrar a
 * un dominio del atacante sigue igual); protege el plano de control del propio lab —
 * mismo principio que el Bloque H con el reset destructivo.
 */
final class AdminController
{
    public function __construct(
        private readonly LabLevelResolver $levels,
    ) {
    }

    #[Route('/api/health', name: 'api_health', methods: ['GET'])]
    public function health(): JsonResponse
    {
        $level = $this->levels->resolve();

        return new JsonResponse([
            'status' => 'ok',
            'level' => $level->value,
            'level_label' => $level->label(),
        ]);
    }

    /**
     * Recarga fixtures + limpia el log durable (barato, sin dropear esquema salvo dano).
     * POST-only: alcanzable por el operador/harness, NO por la GET de `fetch_url` (Q2).
     */
    #[Route('/api/reset', name: 'api_reset', methods: ['POST'])]
    public function reset(LabResetService $reset): JsonResponse
    {
        $reset->reset();

        return new JsonResponse(['status' => 'reset']);
    }

    /**
     * Log consultable de egress (trampa de exfiltracion): proyeccion de las filas
     * `fetch_url` de `ToolInvocation`, con `domain`/`query` parseados server-side (Q1).
     */
    #[Route('/api/exfil', name: 'api_exfil', methods: ['GET'])]
    public function exfil(ExfilProjector $projector): JsonResponse
    {
        return new JsonResponse(['entries' => $projector->entries()]);
    }
}
