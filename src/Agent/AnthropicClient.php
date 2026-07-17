<?php

declare(strict_types=1);

namespace App\Agent;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Cliente de la API de Messages de Anthropic, con el bucle de tool use implementado
 * A MANO (sin SDK), sobre HttpClientInterface (ADR 3). Escribir el marshalling a mano
 * es un requisito: da control total de que entra en el contexto de cada llamada, lo
 * que hara falta para las capas de defensa (y el dual-LLM de la v2).
 *
 * `model`, `temperature` y `max_tokens` se envian EXPLICITAMENTE (ADR 12) y son
 * consultables (los usa `meta` en /api/chat). La ANTHROPIC_API_KEY vive solo en el
 * backend, va en la cabecera x-api-key, y NUNCA aparece en logs ni en excepciones
 * (ver sanitize() + su test).
 */
final class AnthropicClient
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        #[Autowire('%env(ANTHROPIC_API_KEY)%')]
        private readonly string $apiKey,
        #[Autowire('%env(ANTHROPIC_MODEL)%')]
        private readonly string $model,
        #[Autowire('%env(float:ANTHROPIC_TEMPERATURE)%')]
        private readonly float $temperature,
        #[Autowire('%env(int:ANTHROPIC_MAX_TOKENS)%')]
        private readonly int $maxTokens,
        #[Autowire('%env(ANTHROPIC_BASE_URL)%')]
        private readonly string $baseUrl,
        #[Autowire('%env(ANTHROPIC_VERSION)%')]
        private readonly string $version,
    ) {
    }

    public function model(): string
    {
        return $this->model;
    }

    public function temperature(): float
    {
        return $this->temperature;
    }

    public function maxTokens(): int
    {
        return $this->maxTokens;
    }

    /**
     * Una llamada a POST /v1/messages. Devuelve la respuesta decodificada.
     *
     * @param list<array<string, mixed>> $messages
     * @param list<array<string, mixed>> $tools
     *
     * @return array<string, mixed>
     *
     * @throws AnthropicApiException con mensaje saneado (sin la API key)
     */
    public function createMessage(string $system, array $messages, array $tools): array
    {
        try {
            $response = $this->httpClient->request('POST', rtrim($this->baseUrl, '/').'/v1/messages', [
                'headers' => [
                    'x-api-key' => $this->apiKey,
                    'anthropic-version' => $this->version,
                    'content-type' => 'application/json',
                ],
                'json' => [
                    'model' => $this->model,
                    'max_tokens' => $this->maxTokens,
                    'temperature' => $this->temperature,
                    'system' => $system,
                    'messages' => $messages,
                    'tools' => $tools,
                ],
            ]);

            // toArray() lanza en 4xx/5xx; el cuerpo del error NO contiene la key
            // (solo va en la cabecera), pero saneamos igualmente por si acaso.
            return $response->toArray();
        } catch (\Throwable $e) {
            throw new AnthropicApiException($this->sanitize($e->getMessage()));
        }
    }

    /**
     * Elimina la API key de cualquier texto antes de propagarlo (defensa en
     * profundidad: aunque el key solo deberia estar en la cabecera).
     */
    private function sanitize(string $message): string
    {
        if ('' === $this->apiKey) {
            return $message;
        }

        return str_replace($this->apiKey, '[REDACTED]', $message);
    }
}
