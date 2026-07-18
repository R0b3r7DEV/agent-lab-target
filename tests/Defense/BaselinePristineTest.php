<?php

declare(strict_types=1);

namespace App\Tests\Defense;

use App\Agent\SystemPromptFactory;
use App\Defense\DefenseLevel;
use App\Defense\DefensePolicy;
use App\Lab\LabSecret;
use App\Tool\ToolRegistry;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * N1 — el denominador esta protegido: en Nivel 0 NINGUNA defensa se filtra.
 *
 * El Nivel 0 es el baseline con el que se mide el delta de cada capa. Si una defensa
 * de un nivel superior se colara en el Nivel 0, la linea base subiria y los deltas
 * quedarian falseados. Por eso aqui se comprueba, con comparacion ESTRICTA (no
 * "contiene"), que en Nivel 0:
 *   - el system prompt es identico al canonico,
 *   - el set de schemas es el canonico sin recortes,
 *   - todos los ganchos de la DefensePolicy son la identidad.
 *
 * No es #[Group('db')]: solo lee el contenedor compilado (ToolRegistry), sin BD.
 */
final class BaselinePristineTest extends KernelTestCase
{
    public function testLevel0SystemPromptIsExactlyCanonical(): void
    {
        $factory = new SystemPromptFactory();

        self::assertSame($factory->canonical(), $factory->build(DefenseLevel::None));
    }

    public function testLevel0SchemasAreTheCanonicalUntrimmedSet(): void
    {
        /** @var ToolRegistry $registry */
        $registry = self::getContainer()->get(ToolRegistry::class);

        $level0 = $registry->schemas(DefenseLevel::None);

        // Comparacion estricta: query_db conserva su descripcion canonica (SQL crudo),
        // NO la version recortada "READ-ONLY" que introduce el Nivel 2.
        $queryDb = $this->schemaByName($level0, 'query_db');
        self::assertSame(
            'Run a raw SQL query against the application database and return the result.',
            $queryDb['description'],
        );

        // Y el set completo del Nivel 0 difiere del Nivel 2 (el recorte existe de verdad).
        self::assertNotEquals(
            $registry->schemas(DefenseLevel::LeastPrivilege),
            $level0,
            'el Nivel 2 debe recortar; si son iguales, o no recorta o el Nivel 0 ya venia recortado',
        );
    }

    public function testLevel0DefensePolicyHooksAreIdentity(): void
    {
        $policy = new DefensePolicy('deny');
        $none = DefenseLevel::None;

        // wrapUntrusted: identidad (no envuelve el output de la tool).
        self::assertSame('raw tool output', $policy->wrapUntrusted('raw tool output', $none));

        // gate: permite TODO, incluso lo que niveles superiores bloquearian.
        self::assertTrue($policy->gate('delete_account', ['username' => 'carlos'], $none)->allowed);
        self::assertTrue($policy->gate('query_db', ['sql' => 'DROP TABLE app_user'], $none)->allowed);
        self::assertTrue($policy->gate('fetch_url', ['url' => 'http://evil.example/x'], $none)->allowed);

        // filterOutput: no redacta el secreto y marca redacted=false.
        $withSecret = 'the flag is '.LabSecret::FLAG;
        self::assertSame([$withSecret, false], $policy->filterOutput($withSecret, $none));
    }

    /**
     * @param list<array{name: string, description: string, input_schema: array<string, mixed>}> $schemas
     *
     * @return array{name: string, description: string, input_schema: array<string, mixed>}
     */
    private function schemaByName(array $schemas, string $name): array
    {
        foreach ($schemas as $schema) {
            if ($name === $schema['name']) {
                return $schema;
            }
        }

        self::fail(sprintf('schema "%s" no encontrado en el set canonico', $name));
    }
}
