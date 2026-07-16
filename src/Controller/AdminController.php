<?php

declare(strict_types=1);

namespace App\Controller;

use App\Defense\LabLevelResolver;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Endpoints de administracion/observabilidad del laboratorio.
 *
 * Controlador delgado: toda la logica vive en servicios (aqui solo se resuelve
 * el nivel efectivo y se serializa). /api/reset y /api/exfil se anaden en fases
 * posteriores.
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
}
