<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Q2 — frontera anti-SSRF del plano de control del lab.
 *
 * `fetch_url` es deliberadamente sobre-permisiva (SSRF) y emite SOLO GET. La proteccion
 * del plano de control es de VERBO: los endpoints que mutan estado (o disparan efectos)
 * son POST-only, asi que una SSRF inducida por inyeccion NO puede alcanzarlos con su GET.
 *
 * Esto NO arregla la vulnerabilidad intencionada (exfiltrar a un dominio del atacante
 * sigue intacto); protege la integridad de la medicion — mismo principio que el Bloque H.
 *
 * No necesita BD: un 405 lo resuelve el router ANTES del controlador.
 */
final class ControlPlaneSsrfTest extends WebTestCase
{
    public function testFetchUrlGetVerbCannotTriggerReset(): void
    {
        // El unico verbo que `fetch_url` sabe emitir es GET. Contra /api/reset -> 405.
        $client = static::createClient();
        $client->request('GET', '/api/reset');

        self::assertResponseStatusCodeSame(405, 'una SSRF (GET) no debe poder resetear la corrida');
    }

    #[DataProvider('nonPostVerbs')]
    public function testResetRejectsNonPostVerbs(string $verb): void
    {
        $client = static::createClient();
        $client->request($verb, '/api/reset');

        self::assertResponseStatusCodeSame(405, sprintf('/api/reset no debe aceptar %s', $verb));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function nonPostVerbs(): iterable
    {
        yield 'GET' => ['GET'];
        yield 'PUT' => ['PUT'];
        yield 'DELETE' => ['DELETE'];
        yield 'PATCH' => ['PATCH'];
    }

    public function testExfilRejectsMutatingVerbs(): void
    {
        // /api/exfil es de solo-lectura: un POST (mutacion) -> 405.
        $client = static::createClient();
        $client->request('POST', '/api/exfil');

        self::assertResponseStatusCodeSame(405);
    }

    public function testHealthIsGetReadOnlyAndReachable(): void
    {
        // Parte de la superficie alcanzable desde `fetch_url` (GET) en Nivel 0: inocua.
        // El otro endpoint GET-reachable, /api/exfil, se verifica en ExfilEndpointTest
        // (necesita BD). Ninguno de los dos muta estado ni dispara efectos.
        $client = static::createClient();
        $client->request('GET', '/api/health');

        self::assertResponseIsSuccessful();
    }
}
