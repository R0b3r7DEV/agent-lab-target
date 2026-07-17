<?php

declare(strict_types=1);

namespace App\Tests\Fake;

use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Transporte HTTP falso y DETERMINISTA para los tests del bucle (ADR 7). Devuelve
 * cuerpos canónicos de la API de Messages en orden, sin tocar la red. Al operar a
 * nivel de transporte, ejercita el marshalling real del AnthropicClient (headers,
 * cuerpo JSON), no solo una interfaz mockeada.
 */
final class FakeAnthropicTransport
{
    /**
     * @param list<array<string, mixed>> $responseBodies cuerpos decodificados, en orden
     */
    public static function withResponses(array $responseBodies): MockHttpClient
    {
        $mocks = array_map(
            static fn (array $body): MockResponse => new MockResponse(
                (string) json_encode($body),
                ['response_headers' => ['content-type' => 'application/json']],
            ),
            $responseBodies,
        );

        return new MockHttpClient($mocks);
    }

    public static function failing(int $status = 401): MockHttpClient
    {
        return new MockHttpClient([
            new MockResponse(
                '{"type":"error","error":{"type":"authentication_error","message":"invalid request"}}',
                ['http_code' => $status, 'response_headers' => ['content-type' => 'application/json']],
            ),
        ]);
    }

    /**
     * Transporte que captura las opciones de la peticion (para inspeccionar headers y
     * cuerpo, p. ej. el test de que la API key no se filtra).
     *
     * @param array<string, mixed> $captured pasado por referencia; recibe method/url/options
     */
    public static function capturing(array &$captured, int $status = 401): MockHttpClient
    {
        return new MockHttpClient(static function (string $method, string $url, array $options) use (&$captured): MockResponse {
            $captured = ['method' => $method, 'url' => $url, 'options' => $options];

            return new MockResponse(
                '{"type":"error","error":{"type":"authentication_error","message":"invalid request"}}',
                ['http_code' => $status, 'response_headers' => ['content-type' => 'application/json']],
            );
        });
    }

    // --- Helpers para construir cuerpos de respuesta ---

    public static function textBody(string $text): array
    {
        return ['stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => $text]]];
    }

    /**
     * @param array<string, mixed> $input
     */
    public static function toolUseBody(string $id, string $name, array $input): array
    {
        return [
            'stop_reason' => 'tool_use',
            'content' => [['type' => 'tool_use', 'id' => $id, 'name' => $name, 'input' => $input]],
        ];
    }

    public static function maxTokensBody(string $text = ''): array
    {
        return ['stop_reason' => 'max_tokens', 'content' => [['type' => 'text', 'text' => $text]]];
    }
}
