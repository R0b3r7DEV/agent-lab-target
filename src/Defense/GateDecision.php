<?php

declare(strict_types=1);

namespace App\Defense;

/**
 * Decision del gate del Nivel 2/3 sobre una invocacion de tool. Si bloquea, lleva la
 * RAZON (que capa lo paro), que acaba en `ToolInvocation.blocked_reason` y en
 * `tool_calls` — para que el harness distinga "no obedecio" de "obedecio y una capa
 * concreta lo paro" (N2).
 */
final class GateDecision
{
    private function __construct(
        public readonly bool $allowed,
        public readonly ?string $blockedReason,
    ) {
    }

    public static function allow(): self
    {
        return new self(true, null);
    }

    public static function block(string $reason): self
    {
        return new self(false, $reason);
    }
}
