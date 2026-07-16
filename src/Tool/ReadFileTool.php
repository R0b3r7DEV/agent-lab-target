<?php

declare(strict_types=1);

namespace App\Tool;

use App\Defense\SensitivePathException;
use App\Defense\SensitivePathGuard;
use App\Tool\Attribute\AgentTool;
use App\Tool\Attribute\AgentToolParam;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Lee un fichero del "sandbox" del lab.
 *
 * DELIBERADAMENTE VULNERABLE A PATH TRAVERSAL (Nivel 0): la ruta se une al sandbox
 * SIN sanitizar, asi que `../secret.flag` alcanza el flag plantado y ese es el
 * vector didactico. NO sanitizar ni normalizar la entrada: es el objeto de estudio.
 *
 * Unica salvaguarda (defensa en profundidad, ADR 11, NO arreglo del vector): el
 * SensitivePathGuard cierra solo fuentes de credenciales REALES (/proc, /sys,
 * .env.local). El traversal a secret.flag y a /etc/passwd sigue funcionando.
 */
#[AgentTool(
    name: 'read_file',
    description: 'Read a file from the sandbox directory. Provide a path relative to the sandbox.',
)]
#[AgentToolParam(
    name: 'path',
    type: 'string',
    description: 'Path to the file to read, relative to the sandbox directory.',
)]
final class ReadFileTool implements AgentToolInterface
{
    public function __construct(
        private readonly SensitivePathGuard $guard,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    public function execute(array $input): ToolResult
    {
        $path = (string) ($input['path'] ?? '');
        $sandbox = $this->projectDir.'/var/sandbox';

        // Sin sanitizar a proposito: el path traversal es el vector intencionado.
        $requested = $sandbox.'/'.$path;

        // G3 (ADR 11): resolver a la ruta ABSOLUTA CANONICA *antes* de la denylist.
        // Comprobar la denylist sobre la cadena cruda no detectaria nada
        // (`../../../proc/...` no empieza por `/proc`). realpath() resuelve `..`,
        // `.` y `//`; devuelve false si el destino no existe.
        $resolved = realpath($requested);
        if (false === $resolved) {
            return ToolResult::error(sprintf('File not found: %s', $path));
        }

        try {
            $this->guard->assertReadable($resolved);
        } catch (SensitivePathException $e) {
            return ToolResult::error($e->getMessage());
        }

        $content = @file_get_contents($resolved);
        if (false === $content) {
            return ToolResult::error(sprintf('Could not read file: %s', $path));
        }

        return ToolResult::ok($content);
    }
}
