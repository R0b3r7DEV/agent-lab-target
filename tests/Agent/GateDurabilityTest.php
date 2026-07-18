<?php

declare(strict_types=1);

namespace App\Tests\Agent;

use App\Agent\AgentService;
use App\Agent\AnthropicClient;
use App\Agent\SystemPromptFactory;
use App\Defense\DefenseLevel;
use App\Defense\DefensePolicy;
use App\Entity\ToolInvocation;
use App\Tests\Fake\FakeAnthropicTransport;
use App\Tool\ToolRegistry;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;

/**
 * BLOQUE O — DURABILIDAD del gate contra PostgreSQL real (no el orden en memoria).
 *
 * `AgentInvocationOrderTest` prueba el ORDEN de las llamadas (persist->gate->execute) con
 * un espia en memoria. Este prueba lo OTRO, que era el Motivo 1 entero del Bloque I: que
 * una invocacion bloqueada por el gate ATERRIZA en PG con `blocked=true` y su
 * `blocked_reason`, y sobrevive — el caso del request que expira a los 180s. Que la
 * migracion aplique es esquema; esto es comportamiento.
 *
 * Round-trip real: tras el chat se hace `em->clear()` (vacia el identity map) y se
 * RELEE por el repositorio -> Doctrine emite un SELECT contra PG; si la fila esta, es que
 * el flush llego a la base. Sin DAMA, los writes commitean de verdad, asi que
 * `setUp` TRUNCA la tabla para aislar cada test.
 *
 * #[Group('db')]: escribe y relee ToolInvocation en PG18 del compose.
 */
#[Group('db')]
final class GateDurabilityTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        // Aislamiento: sin DAMA las filas commitean y persistirian entre tests.
        $this->em->getConnection()->executeStatement('TRUNCATE tool_invocation RESTART IDENTITY');
    }

    public function testBlockedGateDecisionIsPersistedDurablyWithReason(): void
    {
        // Nivel 2 + politica deny: delete_account es sensible -> el gate lo bloquea.
        $agent = $this->agentAtLevel('deny', FakeAnthropicTransport::withResponses([
            FakeAnthropicTransport::toolUseBody('t1', 'delete_account', ['username' => 'carlos']),
            FakeAnthropicTransport::textBody('I need your confirmation before deleting that account.'),
        ]));

        $result = $agent->chat('delete carlos', DefenseLevel::LeastPrivilege);

        // La proyeccion en la respuesta ya refleja el bloqueo...
        self::assertCount(1, $result->toolCalls);
        self::assertTrue($result->toolCalls[0]->blocked);
        self::assertSame('confirmation_denied', $result->toolCalls[0]->blockedReason);

        // ...y la fuente durable tambien: releida FRESCA desde PG (identity map vacio).
        $rows = $this->reloadFromDb();
        self::assertCount(1, $rows, 'una sola invocacion, registrada aunque el gate la parase');
        $row = $rows[0];
        self::assertSame('delete_account', $row->getTool());
        self::assertTrue($row->isBlocked(), 'blocked debe estar true en PG, no solo en memoria');
        self::assertSame('confirmation_denied', $row->getBlockedReason());
        // No se ejecuto: sin resumen de resultado (se registro el intento, no un efecto).
        self::assertNull($row->getResultSummary());
    }

    public function testAllowedGateDecisionIsPersistedAsNotBlocked(): void
    {
        // Caso simetrico: query_db de solo-lectura NO es sensible -> el gate la permite.
        $agent = $this->agentAtLevel('deny', FakeAnthropicTransport::withResponses([
            FakeAnthropicTransport::toolUseBody('t1', 'query_db', ['sql' => 'SELECT 1']),
            FakeAnthropicTransport::textBody('Here is the result.'),
        ]));

        $agent->chat('read something', DefenseLevel::LeastPrivilege);

        $rows = $this->reloadFromDb();
        self::assertCount(1, $rows);
        $row = $rows[0];
        self::assertSame('query_db', $row->getTool());
        self::assertFalse($row->isBlocked(), 'una llamada permitida se registra con blocked=false');
        self::assertNull($row->getBlockedReason());
        // Se ejecuto: hay resumen del resultado.
        self::assertNotNull($row->getResultSummary());
    }

    /**
     * Relee TODAS las invocaciones desde PG con el identity map vacio (SELECT real).
     *
     * @return list<ToolInvocation>
     */
    private function reloadFromDb(): array
    {
        $this->em->clear();

        return $this->em->getRepository(ToolInvocation::class)->findBy([], ['id' => 'ASC']);
    }

    private function agentAtLevel(string $confirmPolicy, MockHttpClient $http): AgentService
    {
        $container = self::getContainer();

        $anthropic = new AnthropicClient(
            $http,
            'sk-ant-test',
            'claude-haiku-4-5-20251001',
            1.0,
            1024,
            'https://api.anthropic.com',
            '2023-06-01',
        );

        return new AgentService(
            $anthropic,
            $container->get(ToolRegistry::class),
            $container->get(SystemPromptFactory::class),
            new DefensePolicy($confirmPolicy),
            $container->get(EntityManagerInterface::class),
            8,
        );
    }
}
