<?php

declare(strict_types=1);

namespace App\Agent\Dto;

/**
 * Proyeccion de una fila de `ToolInvocation` para el `tool_calls` de /api/chat
 * (Bloque I): NO es una contabilidad paralela, se construye desde el log durable.
 *
 * `blockedReason` (N2) dice QUE capa lo paro, para que el harness distinga "no
 * obedecio" de "obedecio y una capa concreta lo paro".
 */
final class ToolCallRecord
{
    /**
     * @param array<string, mixed> $input
     */
    public function __construct(
        public readonly string $name,
        public readonly array $input,
        public readonly ?string $resultSummary,
        public readonly bool $blocked,
        public readonly ?string $blockedReason,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'input' => $this->input,
            'result_summary' => $this->resultSummary,
            'blocked' => $this->blocked,
            'blocked_reason' => $this->blockedReason,
        ];
    }
}
