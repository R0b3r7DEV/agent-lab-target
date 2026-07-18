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
     * El SET (nombres + input_schemas) es agnostico del nivel: el compiler pass genera
     * el conjunto canonico completo una sola vez (Cambio 4). Lo unico que cambia por
     * nivel es la DESCRIPCION anunciada de query_db, recortada en RUNTIME a partir del
     * Nivel 2 (minimo privilegio: se anuncia solo-lectura). La ejecucion la fuerza el
     * gate de la DefensePolicy; aqui solo se ajusta lo que ve el modelo.
     */
    public function testToolSetAndSchemasAreLevelIndependent(): void
    {
        $registry = $this->registry();

        foreach ([DefenseLevel::None, DefenseLevel::DataSeparation, DefenseLevel::LeastPrivilege, DefenseLevel::OutputFiltering] as $level) {
            $schemas = $registry->schemas($level);
            self::assertCount(5, $schemas, sprintf('el set debe tener 5 tools en %s', $level->name));
            self::assertEqualsCanonicalizing(self::EXPECTED_TOOLS, array_column($schemas, 'name'), 'los nombres del set son invariantes');
            self::assertSame(
                $this->schemaOf($registry->schemas(DefenseLevel::None), 'query_db')['input_schema'],
                $this->schemaOf($schemas, 'query_db')['input_schema'],
                'el input_schema de query_db no cambia por nivel; solo la descripcion',
            );
        }
    }

    /**
     * El recorte de descripcion de query_db ocurre en RUNTIME y solo desde el Nivel 2.
     */
    public function testQueryDbDescriptionIsTrimmedFromLevel2(): void
    {
        $registry = $this->registry();
        $canonical = 'Run a raw SQL query against the application database and return the result.';
        $trimmed = 'Run a READ-ONLY SQL SELECT query against the database and return the result.';

        self::assertSame($canonical, $this->schemaOf($registry->schemas(DefenseLevel::None), 'query_db')['description']);
        self::assertSame($canonical, $this->schemaOf($registry->schemas(DefenseLevel::DataSeparation), 'query_db')['description']);
        self::assertSame($trimmed, $this->schemaOf($registry->schemas(DefenseLevel::LeastPrivilege), 'query_db')['description']);
        self::assertSame($trimmed, $this->schemaOf($registry->schemas(DefenseLevel::OutputFiltering), 'query_db')['description']);
    }

    /**
     * @param list<array{name: string, description: string, input_schema: array<string, mixed>}> $schemas
     *
     * @return array{name: string, description: string, input_schema: array<string, mixed>}
     */
    private function schemaOf(array $schemas, string $name): array
    {
        foreach ($schemas as $schema) {
            if ($name === $schema['name']) {
                return $schema;
            }
        }

        self::fail(sprintf('schema "%s" no encontrado', $name));
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
