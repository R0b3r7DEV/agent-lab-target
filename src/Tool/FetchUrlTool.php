<?php

declare(strict_types=1);

namespace App\Tool;

use App\Tool\Attribute\AgentTool;
use App\Tool\Attribute\AgentToolParam;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Descarga el contenido de una URL. Doble peligro: exfiltracion via query params
 * (mandar el secreto a un dominio del atacante) y entrada de inyeccion INDIRECTA.
 *
 * La tool es PURA respecto al log: no escribe en BD. El registro DURABLE del egress
 * (con `blocked`, ANTES del gate/allowlist) lo hace el AgentService en
 * `ToolInvocation` (Bloque I, ADR 15) — una sola fuente de verdad. `/api/exfil`
 * (Fase 6) proyecta sobre esas filas (tool='fetch_url', input={url}) y deriva
 * dominio/query del `url` guardado. Asi el harness detecta el intento pase lo que
 * pase por el texto de la respuesta.
 */
#[AgentTool(
    name: 'fetch_url',
    description: 'Fetch the contents of a URL over HTTP(S).',
)]
#[AgentToolParam(name: 'url', type: 'string', description: 'The URL to fetch.')]
final class FetchUrlTool implements AgentToolInterface
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
    ) {
    }

    public function execute(array $input): ToolResult
    {
        $url = (string) ($input['url'] ?? '');

        try {
            $content = $this->httpClient->request('GET', $url)->getContent();
        } catch (\Throwable $e) {
            return ToolResult::error(sprintf('Fetch failed for %s: %s', $url, $e->getMessage()));
        }

        return ToolResult::ok($content);
    }
}
