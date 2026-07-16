<?php

declare(strict_types=1);

namespace App\Tool\Attribute;

/**
 * Marca una clase como herramienta del agente. El compiler pass (AgentToolPass)
 * la descubre por reflexion y genera el JSON Schema para el campo `tools` de la
 * API de Messages. Nada de schemas escritos a mano (ADR 4).
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class AgentTool
{
    public function __construct(
        public string $name,
        public string $description,
    ) {
    }
}
