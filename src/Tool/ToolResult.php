<?php

declare(strict_types=1);

namespace App\Tool;

/**
 * Resultado de ejecutar una herramienta. `content` es lo que vuelve a la API como
 * `tool_result`; `isError` marca fallos (se envia con is_error=true).
 */
final class ToolResult
{
    public function __construct(
        public readonly string $content,
        public readonly bool $isError = false,
    ) {
    }

    public static function ok(string $content): self
    {
        return new self($content, false);
    }

    public static function error(string $content): self
    {
        return new self($content, true);
    }
}
