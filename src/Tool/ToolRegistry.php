<?php

declare(strict_types=1);

namespace App\Tool;

use App\Defense\DefenseLevel;
use Psr\Container\ContainerInterface;

/**
 * Registro de herramientas. Sus datos los inyecta AgentToolPass en tiempo de
 * compilacion: `$definitions` (el set CANONICO y COMPLETO de schemas) y `$tools`
 * (un service locator para ejecutarlas por nombre).
 *
 * El set es AGNOSTICO DEL NIVEL (Cambio 4): el pass no conoce LAB_LEVEL. El
 * filtrado/recorte por nivel ocurre aqui, en RUNTIME, en schemas().
 */
final class ToolRegistry
{
    /**
     * @param array<string, array{name: string, description: string, input_schema: array<string, mixed>}> $definitions
     */
    public function __construct(
        private readonly array $definitions,
        private readonly ContainerInterface $tools,
    ) {
    }

    /**
     * Payload del campo `tools` de la API de Messages, resuelto por nivel en RUNTIME.
     *
     * Nivel 0: el set canonico completo, sin recortes (baseline pristino, N1). El
     * recorte por nivel ocurre AQUI, en runtime (Cambio 4): a partir del Nivel 2,
     * query_db se anuncia como solo-lectura (la ejecucion la fuerza el gate de la
     * DefensePolicy; aqui se ajusta lo que ve el modelo).
     *
     * @return list<array{name: string, description: string, input_schema: array<string, mixed>}>
     */
    public function schemas(DefenseLevel $level): array
    {
        $definitions = $this->definitions;

        if ($level->value >= DefenseLevel::LeastPrivilege->value && isset($definitions['query_db'])) {
            $definitions['query_db']['description'] =
                'Run a READ-ONLY SQL SELECT query against the database and return the result.';
        }

        return array_values($definitions);
    }

    public function has(string $name): bool
    {
        return $this->tools->has($name);
    }

    public function get(string $name): AgentToolInterface
    {
        /** @var AgentToolInterface */
        return $this->tools->get($name);
    }

    /**
     * @return list<string> nombres de todas las herramientas registradas (canonico)
     */
    public function names(): array
    {
        return array_keys($this->definitions);
    }
}
