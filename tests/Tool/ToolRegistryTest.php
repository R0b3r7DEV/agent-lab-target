<?php

declare(strict_types=1);

namespace App\Tests\Tool;

use App\Defense\DefenseLevel;
use App\Tool\ToolRegistry;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Verifica el registro por atributos + compiler pass (ADR 4):
 *  - Las 5 herramientas se descubren y generan schema.
 *  - El schema tiene la forma que espera el campo `tools` de la API de Messages.
 *  - El set es CANONICO y AGNOSTICO DEL NIVEL (Cambio 4): el pass genera el conjunto
 *    completo y no lee LAB_LEVEL.
 */
final class ToolRegistryTest extends KernelTestCase
{
    private const array EXPECTED_TOOLS = [
        'read_file',
        'send_email',
        'fetch_url',
        'query_db',
        'delete_account',
    ];

    private function registry(): ToolRegistry
    {
        self::bootKernel();

        return self::getContainer()->get(ToolRegistry::class);
    }

    public function testAllFiveToolsAreRegistered(): void
    {
        $names = $this->registry()->names();

        self::assertCount(5, $names);
        foreach (self::EXPECTED_TOOLS as $expected) {
            self::assertContains($expected, $names);
        }
    }

    public function testSchemaMatchesMessagesApiShape(): void
    {
        $schemas = $this->registry()->schemas(DefenseLevel::None);

        foreach ($schemas as $tool) {
            self::assertArrayHasKey('name', $tool);
            self::assertArrayHasKey('description', $tool);
            self::assertArrayHasKey('input_schema', $tool);

            $schema = $tool['input_schema'];
            self::assertSame('object', $schema['type']);
            self::assertArrayHasKey('properties', $schema);
            self::assertIsArray($schema['properties']);
            if (\array_key_exists('required', $schema)) {
                self::assertIsList($schema['required']);
            }
        }
    }

    public function testReadFileSchemaIsExact(): void
    {
        $schemas = $this->registry()->schemas(DefenseLevel::None);
        $byName = [];
        foreach ($schemas as $tool) {
            $byName[$tool['name']] = $tool;
        }

        $readFile = $byName['read_file'];
        self::assertSame('object', $readFile['input_schema']['type']);
        self::assertSame('string', $readFile['input_schema']['properties']['path']['type']);
        self::assertSame(['path'], $readFile['input_schema']['required']);
    }

    /**
     * El conjunto es agnostico del nivel: pedir los schemas a cualquier nivel
     * devuelve el mismo set canonico (en Nivel 0; los recortes por nivel son Fase 5).
     */
    public function testCanonicalSetIsLevelIndependent(): void
    {
        $registry = $this->registry();

        $none = $registry->schemas(DefenseLevel::None);
        $least = $registry->schemas(DefenseLevel::LeastPrivilege);
        $output = $registry->schemas(DefenseLevel::OutputFiltering);

        self::assertSame($none, $least);
        self::assertSame($none, $output);
        self::assertCount(5, $none);
    }

    /**
     * Guarda dura del Cambio 4: el compiler pass NO debe RESOLVER parametros del
     * contenedor (getParameter/getParameterBag), porque en compile time recibiria el
     * placeholder de LAB_LEVEL, no el valor. Se verifica sobre el fuente del pass.
     * (El invariante de comportamiento lo cubre testCanonicalSetIsLevelIndependent;
     * este anade el guard del mecanismo.)
     */
    public function testCompilerPassDoesNotResolveContainerParameters(): void
    {
        $source = (string) file_get_contents(
            \dirname(__DIR__, 2).'/src/DependencyInjection/Compiler/AgentToolPass.php',
        );

        self::assertStringNotContainsStringIgnoringCase('getParameter', $source);
        self::assertStringNotContainsStringIgnoringCase('getParameterBag', $source);
    }
}
