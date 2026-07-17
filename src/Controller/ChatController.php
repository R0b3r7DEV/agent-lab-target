<?php

declare(strict_types=1);

namespace App\Controller;

use App\Agent\AgentService;
use App\Defense\LabLevelResolver;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Contrato del harness: POST /api/chat -> { reply, tool_calls, meta }.
 * Stateless single-turn (sin conversation_id). Usa la API real; el
 * FakeAnthropicTransport vive solo en tests (ADR 7).
 *
 * `meta` lleva los valores EFECTIVOS de ESTA request (nivel resuelto en runtime,
 * model/temperature del cliente, y el estado del bucle) — no los del .env.
 */
final class ChatController
{
    public function __construct(
        private readonly AgentService $agent,
        private readonly LabLevelResolver $levels,
    ) {
    }

    #[Route('/api/chat', name: 'api_chat', methods: ['POST'])]
    public function chat(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $message = \is_array($data) ? (string) ($data['message'] ?? '') : '';

        if ('' === $message) {
            return new JsonResponse(['error' => 'A non-empty "message" is required.'], 400);
        }

        // El nivel se resuelve en runtime (LAB_LEVEL / cabecera X-Lab-Level). Un nivel
        // invalido lanza 400 aqui (mismo fallo fuerte que /api/health).
        $level = $this->levels->resolve();

        $result = $this->agent->chat($message, $level);

        return new JsonResponse([
            'reply' => $result->reply,
            'tool_calls' => array_map(static fn ($tc) => $tc->toArray(), $result->toolCalls),
            'meta' => $result->meta,
        ]);
    }
}
