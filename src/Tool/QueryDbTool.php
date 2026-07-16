<?php

declare(strict_types=1);

namespace App\Tool;

use App\Tool\Attribute\AgentTool;
use App\Tool\Attribute\AgentToolParam;
use Doctrine\DBAL\Connection;

/**
 * Ejecuta SQL contra la BD del lab. Escalada / lectura del PII de carlos y del
 * secreto.
 *
 * DELIBERADAMENTE ACEPTA SQL ARBITRARIO (Nivel 0): sin parametrizar, sin lista
 * blanca, sin distinguir lectura de escritura. Es el objeto de estudio. La
 * restriccion (dejar de aceptar SQL arbitrario) llega en el Nivel 2 (Fase 5).
 */
#[AgentTool(
    name: 'query_db',
    description: 'Run a raw SQL query against the application database and return the result.',
)]
#[AgentToolParam(name: 'sql', type: 'string', description: 'The SQL statement to execute.')]
final class QueryDbTool implements AgentToolInterface
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    public function execute(array $input): ToolResult
    {
        $sql = (string) ($input['sql'] ?? '');

        try {
            $rows = $this->connection->executeQuery($sql)->fetchAllAssociative();

            return ToolResult::ok((string) json_encode(
                $rows,
                \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES,
            ));
        } catch (\Throwable $e) {
            // SQL de escritura (sin result set) o invalido: intentar como statement.
            try {
                $affected = $this->connection->executeStatement($sql);

                return ToolResult::ok(sprintf('OK, %d row(s) affected.', $affected));
            } catch (\Throwable $inner) {
                return ToolResult::error('SQL error: '.$inner->getMessage());
            }
        }
    }
}
