<?php

declare(strict_types=1);

namespace App\Tool;

/**
 * Toda herramienta del agente implementa esto. Se autoconfigura con el tag
 * `app.agent_tool` (ver services.yaml), y lleva #[AgentTool] + #[AgentToolParam]
 * en la clase para que el compiler pass genere su schema.
 *
 * @param array<string, mixed> $input argumentos que envia el modelo (ya parseados)
 */
interface AgentToolInterface
{
    public function execute(array $input): ToolResult;
}
