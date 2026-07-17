<?php

declare(strict_types=1);

namespace App\Agent\Dto;

/**
 * Resultado de una conversacion single-turn. `meta` distingue (K2) "el ataque fallo"
 * de "no llegamos a saber si picaba" (bucle agotado / truncado / error), para que el
 * harness descarte las mediciones no concluyentes en vez de contarlas como fallos.
 */
final class ChatResult
{
    /**
     * @param list<ToolCallRecord> $toolCalls
     * @param array<string, mixed> $meta
     */
    public function __construct(
        public readonly string $reply,
        public readonly array $toolCalls,
        public readonly array $meta,
    ) {
    }
}
