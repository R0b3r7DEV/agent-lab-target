<?php

declare(strict_types=1);

namespace App\Tests\Agent;

use App\Agent\AgentService;
use App\Agent\AnthropicClient;
use App\Agent\SystemPromptFactory;
use App\Defense\DefenseLevel;
use App\Defense\DefensePolicy;
use App\Tests\Fake\FakeAnthropicTransport;
use App\Tool\ToolRegistry;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;

/**
 * K3: el bucle de tool use con el transporte falso. Cubre: una vuelta de solo texto,
 * tool use de una vuelta, encadenado de varias, agotamiento de MAX_ITER, error de API,
 * y truncado. Ademas verifica que `tool_calls` es la proyeccion del log durable y que
 * `meta` distingue "el ataque fallo" de "no concluyente" (K2).
 *
 * #[Group('db')]: el AgentService persiste `ToolInvocation`.
 */
#[Group('db')]
final class AgentServiceTest extends KernelTestCase
{
    private function agentWith(MockHttpClient $http, int $maxIterations = 8): AgentService
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
            new DefensePolicy('deny'),
            $container->get(EntityManagerInterface::class),
            $maxIterations,
        );
    }

    public function testSingleTurnTextResponse(): void
    {
        $agent = $this->agentWith(FakeAnthropicTransport::withResponses([
            FakeAnthropicTransport::textBody('Hello, how can I help?'),
        ]));

        $result = $agent->chat('hi', DefenseLevel::None);

        self::assertSame('Hello, how can I help?', $result->reply);
        self::assertCount(0, $result->toolCalls);
        self::assertSame('end_turn', $result->meta['stop_reason']);
        self::assertSame(1, $result->meta['iterations']);
        self::assertFalse($result->meta['api_error']);
        self::assertFalse($result->meta['max_iterations_reached']);
        self::assertFalse($result->meta['truncated']);
        self::assertSame(0, $result->meta['level']);
    }

    public function testSingleToolUseThenText(): void
    {
        $agent = $this->agentWith(FakeAnthropicTransport::withResponses([
            FakeAnthropicTransport::toolUseBody('tu_1', 'send_email', ['to' => 'x@lab.local', 'body' => 'hi']),
            FakeAnthropicTransport::textBody('Done.'),
        ]));

        $result = $agent->chat('email x', DefenseLevel::None);

        self::assertSame('Done.', $result->reply);
        self::assertCount(1, $result->toolCalls);
        self::assertSame('send_email', $result->toolCalls[0]->name);
        self::assertFalse($result->toolCalls[0]->blocked);
        self::assertStringContainsString('Email sent', (string) $result->toolCalls[0]->resultSummary);
        self::assertSame(2, $result->meta['iterations']);
    }

    public function testChainedToolUse(): void
    {
        $agent = $this->agentWith(FakeAnthropicTransport::withResponses([
            FakeAnthropicTransport::toolUseBody('t1', 'send_email', ['to' => 'a@lab.local', 'body' => '1']),
            FakeAnthropicTransport::toolUseBody('t2', 'send_email', ['to' => 'b@lab.local', 'body' => '2']),
            FakeAnthropicTransport::textBody('All sent.'),
        ]));

        $result = $agent->chat('two emails', DefenseLevel::None);

        self::assertSame('All sent.', $result->reply);
        self::assertCount(2, $result->toolCalls);
        self::assertSame(3, $result->meta['iterations']);
    }

    public function testMaxIterationsReachedIsMarked(): void
    {
        $agent = $this->agentWith(FakeAnthropicTransport::withResponses([
            FakeAnthropicTransport::toolUseBody('t1', 'send_email', ['to' => 'a@lab.local', 'body' => '1']),
            FakeAnthropicTransport::toolUseBody('t2', 'send_email', ['to' => 'b@lab.local', 'body' => '2']),
        ]), maxIterations: 2);

        $result = $agent->chat('loop forever', DefenseLevel::None);

        self::assertTrue($result->meta['max_iterations_reached']);
        self::assertSame(2, $result->meta['iterations']);
        self::assertCount(2, $result->toolCalls);
    }

    public function testApiErrorIsMarkedNotConflatedWithAttackFailure(): void
    {
        $agent = $this->agentWith(FakeAnthropicTransport::failing(500));

        $result = $agent->chat('hi', DefenseLevel::None);

        self::assertTrue($result->meta['api_error'], 'un error de API debe marcarse, no confundirse con un ataque fallido');
        self::assertSame('api_error', $result->meta['stop_reason']);
    }

    public function testTruncatedIsMarked(): void
    {
        $agent = $this->agentWith(FakeAnthropicTransport::withResponses([
            FakeAnthropicTransport::maxTokensBody('partial answer'),
        ]));

        $result = $agent->chat('hi', DefenseLevel::None);

        self::assertTrue($result->meta['truncated']);
        self::assertSame('max_tokens', $result->meta['stop_reason']);
    }
}
