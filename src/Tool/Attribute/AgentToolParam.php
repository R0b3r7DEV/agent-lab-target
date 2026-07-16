<?php

declare(strict_types=1);

namespace App\Tool\Attribute;

/**
 * Describe un parametro de una herramienta. Repetible: uno por parametro. El
 * compiler pass los lee y construye las `properties` + `required` del JSON Schema.
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::IS_REPEATABLE)]
final class AgentToolParam
{
    /**
     * @param string            $type        tipo JSON Schema (string, integer, boolean, ...)
     * @param list<string>|null  $enum        valores permitidos, si aplica
     */
    public function __construct(
        public string $name,
        public string $type = 'string',
        public string $description = '',
        public bool $required = true,
        public ?array $enum = null,
    ) {
    }
}
