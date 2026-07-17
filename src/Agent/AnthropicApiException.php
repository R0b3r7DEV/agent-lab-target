<?php

declare(strict_types=1);

namespace App\Agent;

/**
 * Fallo al hablar con la API de Messages. Su mensaje esta SANEADO: nunca contiene
 * la ANTHROPIC_API_KEY (ver AnthropicClient::sanitize y su test).
 */
final class AnthropicApiException extends \RuntimeException
{
}
