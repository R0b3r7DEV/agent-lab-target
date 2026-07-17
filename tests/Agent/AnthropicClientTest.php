<?php

declare(strict_types=1);

namespace App\Tests\Agent;

use App\Agent\AnthropicApiException;
use App\Agent\AnthropicClient;
use App\Tests\Fake\FakeAnthropicTransport;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;

/**
 * K1: la ANTHROPIC_API_KEY nunca se filtra. Se fuerza un fallo de la API y se afirma
 * que la key no aparece en el mensaje de excepcion, y que va en la cabecera x-api-key,
 * nunca en el cuerpo de la peticion.
 */
final class AnthropicClientTest extends TestCase
{
    private const string KEY = 'sk-ant-LEAKTEST-0123456789abcdef0123456789';

    private function client(MockHttpClient $http): AnthropicClient
    {
        return new AnthropicClient(
            $http,
            self::KEY,
            'claude-haiku-4-5-20251001',
            1.0,
            1024,
            'https://api.anthropic.com',
            '2023-06-01',
        );
    }

    public function testApiKeyNotInExceptionMessageOnFailure(): void
    {
        $client = $this->client(FakeAnthropicTransport::failing(401));

        try {
            $client->createMessage('sys', [['role' => 'user', 'content' => 'hi']], []);
            self::fail('debia lanzar AnthropicApiException');
        } catch (AnthropicApiException $e) {
            self::assertStringNotContainsString(self::KEY, $e->getMessage());
        }
    }

    public function testApiKeyGoesInHeaderNeverInBody(): void
    {
        $captured = [];
        $client = $this->client(FakeAnthropicTransport::capturing($captured, 500));

        try {
            $client->createMessage('sys', [['role' => 'user', 'content' => 'hi']], []);
        } catch (AnthropicApiException) {
            // esperado
        }

        $headers = (array) ($captured['options']['headers'] ?? []);
        $headerBlob = implode("\n", array_map(
            static fn ($v): string => \is_array($v) ? implode(',', $v) : (string) $v,
            $headers,
        ));
        self::assertStringContainsString(self::KEY, $headerBlob, 'la key debe ir en la cabecera x-api-key');

        $body = $captured['options']['body'] ?? '';
        $body = \is_string($body) ? $body : '';
        self::assertStringNotContainsString(self::KEY, $body, 'la key NO debe ir en el cuerpo de la peticion');
    }
}
