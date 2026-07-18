<?php

declare(strict_types=1);

namespace App\Agent;

use App\Agent\Dto\ChatResult;
use App\Agent\Dto\ToolCallRecord;
use App\Defense\DefenseLevel;
use App\Defense\DefensePolicy;
use App\Entity\ToolInvocation;
use App\Tool\ToolRegistry;
use App\Tool\ToolResult;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * El agente vulnerable: bucle de tool use a mano (ADR 3).
 *
 * Por cada bloque tool_use del modelo:
 *  1. Registra un `ToolInvocation` DURABLE (con `blocked`, ANTES del gate) — Bloque I.
 *     En Nivel 0 no hay gate, asi que blocked=false; la Fase 5 insertara el gate ENTRE
 *     este persist y la ejecucion, y marcara blocked segun el nivel.
 *  2. Ejecuta la tool (Nivel 0: siempre) y guarda el resumen del resultado.
 *  3. Devuelve el tool_result al modelo. Repite hasta que pare o se agote MAX_ITER.
 *
 * `tool_calls` de /api/chat es la PROYECCION de esos ToolInvocation. `meta` distingue
 * "el ataque fallo" de "no concluyente" (K2).
 */
final class AgentService
{
    public function __construct(
        private readonly AnthropicClient $anthropic,
        private readonly ToolRegistry $tools,
        private readonly SystemPromptFactory $systemPrompts,
        private readonly DefensePolicy $defense,
        private readonly EntityManagerInterface $em,
        #[Autowire('%env(int:AGENT_MAX_ITERATIONS)%')]
        private readonly int $maxIterations,
    ) {
    }

    public function chat(string $message, DefenseLevel $level): ChatResult
    {
        $system = $this->systemPrompts->build($level);
        $toolSchemas = $this->tools->schemas($level);
        $messages = [['role' => 'user', 'content' => $message]];

        /** @var list<ToolInvocation> $invocations */
        $invocations = [];
        $content = [];
        $iterations = 0;
        $stopReason = null;
        $maxReached = false;
        $apiError = false;

        while (true) {
            if ($iterations >= max(1, $this->maxIterations)) {
                $maxReached = true;
                break;
            }
            ++$iterations;

            try {
                $response = $this->anthropic->createMessage($system, $messages, $toolSchemas);
            } catch (AnthropicApiException) {
                // K2: un error de API NO es "el ataque fallo". Se marca en meta para que
                // el harness descarte la medicion en vez de contarla como fallo.
                $apiError = true;
                $stopReason = 'api_error';
                break;
            }

            $stopReason = isset($response['stop_reason']) ? (string) $response['stop_reason'] : null;
            $content = \is_array($response['content'] ?? null) ? $response['content'] : [];

            if ('tool_use' !== $stopReason) {
                break;
            }

            $toolResults = [];
            foreach ($content as $block) {
                if (!\is_array($block) || 'tool_use' !== ($block['type'] ?? null)) {
                    continue;
                }
                $name = (string) ($block['name'] ?? '');
                $input = \is_array($block['input'] ?? null) ? $block['input'] : [];

                // Bloque I: registro DURABLE, ANTES del gate y de ejecutar (sobrevive a un
                // timeout). Orden invariante: persist -> gate -> execute (Bloque L/N4).
                $invocation = new ToolInvocation($name, $input, false);
                $this->em->persist($invocation);
                $this->em->flush();
                $invocations[] = $invocation;

                // NIVEL 2/3: gate. DESPUES del persist y ANTES de ejecutar: una llamada
                // bloqueada queda registrada (blocked=true + razon), distinguible de
                // "nunca intentada" (Cambio 1).
                $decision = $this->defense->gate($name, $input, $level);

                if (!$decision->allowed) {
                    $invocation->block((string) $decision->blockedReason);
                    $this->em->flush();
                    $resultContent = sprintf(
                        'This action was blocked by policy (%s). You must ask the user to confirm before proceeding.',
                        $decision->blockedReason,
                    );
                    $isError = true;
                } else {
                    $result = $this->tools->has($name)
                        ? $this->tools->get($name)->execute($input)
                        : ToolResult::error(sprintf('Unknown tool: %s', $name));

                    $invocation->setResultSummary(self::summarize($result->content));
                    $this->em->flush();

                    // NIVEL 1: el output de la tool es contenido NO CONFIABLE -> se marca
                    // como datos, no instrucciones, antes de devolverlo al modelo.
                    $resultContent = $this->defense->wrapUntrusted($result->content, $level);
                    $isError = $result->isError;
                }

                $toolResults[] = [
                    'type' => 'tool_result',
                    'tool_use_id' => (string) ($block['id'] ?? ''),
                    'content' => $resultContent,
                    'is_error' => $isError,
                ];
            }

            $messages[] = ['role' => 'assistant', 'content' => $content];
            $messages[] = ['role' => 'user', 'content' => $toolResults];
        }

        $toolCalls = array_map(
            static fn (ToolInvocation $i): ToolCallRecord => new ToolCallRecord(
                $i->getTool(),
                $i->getInput(),
                $i->getResultSummary(),
                $i->isBlocked(),
                $i->getBlockedReason(),
            ),
            $invocations,
        );

        // NIVEL 3: DLP sobre la salida final antes de devolverla.
        [$reply, $dlpRedacted] = $this->defense->filterOutput(self::extractText($content), $level);

        $meta = [
            'level' => $level->value,
            'model' => $this->anthropic->model(),
            'temperature' => $this->anthropic->temperature(),
            'max_tokens' => $this->anthropic->maxTokens(),
            'iterations' => $iterations,
            'stop_reason' => $stopReason,
            'max_iterations_reached' => $maxReached,
            'truncated' => 'max_tokens' === $stopReason,
            'api_error' => $apiError,
            'dlp_redacted' => $dlpRedacted,
        ];

        return new ChatResult($reply, $toolCalls, $meta);
    }

    /**
     * @param list<mixed> $content
     */
    private static function extractText(array $content): string
    {
        $parts = [];
        foreach ($content as $block) {
            if (\is_array($block) && 'text' === ($block['type'] ?? null)) {
                $parts[] = (string) ($block['text'] ?? '');
            }
        }

        return implode('', $parts);
    }

    private static function summarize(string $content): string
    {
        return mb_substr($content, 0, 500);
    }
}
