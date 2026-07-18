<?php

declare(strict_types=1);

namespace App\Tests\Agent;

use App\Agent\AgentService;
use App\Agent\AnthropicClient;
use App\Agent\SystemPromptFactory;
use App\Defense\DefenseLevel;
use App\Tests\Fake\FakeAnthropicTransport;
use App\Tool\AgentToolInterface;
use App\Tool\ToolRegistry;
use App\Tool\ToolResult;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;

/**
 * BLOQUE L / J — el invariante de orden, clavado en un test (no en una nota).
 *
 * Registrar el `ToolInvocation` (persist) DEBE ocurrir ANTES de ejecutar la tool. Al
 * vivir el registro en el orquestador (AgentService), un solo test lo garantiza para
 * las 5 tools.
 *
 * FASE 5 (Bloque J/N4): cuando se inserte el gate, la asercion pasa a
 * ['persist', 'gate', 'execute'] — se anade UN elemento. Registrar despues del gate
 * haria una llamada bloqueada indistinguible de "nunca intentada" (Cambio 1).
 *
 * Unitario puro: EM como stub (persist registra el orden), tool espia (execute
 * registra el orden). Sin BD.
 */
final class AgentInvocationOrderTest extends TestCase
{
    public function testInvocationIsPersistedBeforeToolIsExecuted(): void
    {
        $order = [];
        $record = static function (string $step) use (&$order): void {
            $order[] = $step;
        };

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(static function () use ($record): void {
            $record('persist');
        });

        $spyTool = new class($record) implements AgentToolInterface {
            public function __construct(private readonly \Closure $record)
            {
            }

            public function execute(array $input): ToolResult
            {
                ($this->record)('execute');

                return ToolResult::ok('spy executed');
            }
        };

        $registry = new ToolRegistry(
            ['spy_tool' => ['name' => 'spy_tool', 'description' => '', 'input_schema' => ['type' => 'object', 'properties' => []]]],
            new ServiceLocator(['spy_tool' => static fn (): AgentToolInterface => $spyTool]),
        );

        $anthropic = new AnthropicClient(
            FakeAnthropicTransport::withResponses([
                FakeAnthropicTransport::toolUseBody('t1', 'spy_tool', ['x' => 1]),
                FakeAnthropicTransport::textBody('done'),
            ]),
            'sk-ant-test',
            'claude-haiku-4-5-20251001',
            1.0,
            1024,
            'https://api.anthropic.com',
            '2023-06-01',
        );

        $agent = new AgentService($anthropic, $registry, new SystemPromptFactory(), $em, 8);
        $agent->chat('go', DefenseLevel::None);

        // Hoy: persist antes de execute. Fase 5: ['persist', 'gate', 'execute'].
        self::assertSame(['persist', 'execute'], $order);
    }
}
