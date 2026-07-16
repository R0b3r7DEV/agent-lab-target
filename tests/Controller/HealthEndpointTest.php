<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Verificacion funcional del contrato de GET /api/health a traves del kernel
 * completo (no del servidor embebido): nivel efectivo runtime, override por
 * cabecera, fallo fuerte (400) ante nivel invalido y 405 en metodo no permitido.
 */
final class HealthEndpointTest extends WebTestCase
{
    public function testDefaultLevelIsZero(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/health');

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true);

        self::assertSame('ok', $data['status']);
        self::assertSame(0, $data['level']);
    }

    public function testHeaderOverridesToLevelThree(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/health', server: ['HTTP_X_LAB_LEVEL' => '3']);

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true);

        self::assertSame(3, $data['level']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidHeaderValues(): iterable
    {
        yield 'fuera de rango' => ['99'];
        yield 'off-by-one 4' => ['4'];
        yield 'negativo' => ['-1'];
        yield 'no numerico' => ['abc'];
    }

    #[DataProvider('invalidHeaderValues')]
    public function testInvalidLevelReturns400(string $value): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/health', server: ['HTTP_X_LAB_LEVEL' => $value]);

        self::assertResponseStatusCodeSame(400);
    }

    public function testMethodNotAllowedReturns405(): void
    {
        $client = static::createClient();
        $client->request('POST', '/api/health');

        self::assertResponseStatusCodeSame(405);
    }
}
