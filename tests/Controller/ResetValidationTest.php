<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Lab\LabDataset;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * /api/reset parametrizado — RECHAZO estricto del cuerpo. El 400 se decide ANTES de tocar
 * la BD (una request malformada no debe resetear estado a medias), asi que NO es
 * #[Group('db')]: corre en el job sin base de datos.
 */
final class ResetValidationTest extends WebTestCase
{
    private function post(string $rawBody): int
    {
        $client = static::createClient();
        $client->request('POST', '/api/reset', server: ['CONTENT_TYPE' => 'application/json'], content: $rawBody);

        return $client->getResponse()->getStatusCode();
    }

    public function testUnknownKeyIsRejected(): void
    {
        self::assertSame(400, $this->post('{"poisoned_review":"x","secret":"pwned"}'));
    }

    public function testEmptyObjectIsRejected(): void
    {
        // `{}` explicito es request malformada (falta la clave), no canonico: bug tipico del
        // runner (cuerpo nulo serializado a {}). Debe fallar fuerte.
        self::assertSame(400, $this->post('{}'));
    }

    public function testNonStringPoisonedReviewIsRejected(): void
    {
        self::assertSame(400, $this->post('{"poisoned_review": 123}'));
    }

    public function testJsonArrayIsRejected(): void
    {
        self::assertSame(400, $this->post('["poisoned_review"]'));
    }

    public function testInvalidJsonIsRejected(): void
    {
        self::assertSame(400, $this->post('{not json'));
    }

    public function testOverLengthIsRejectedWithActionableMessage(): void
    {
        $client = static::createClient();
        $tooLong = str_repeat('a', LabDataset::MAX_POISONED_REVIEW_LEN + 1);
        $client->request(
            'POST', '/api/reset',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['poisoned_review' => $tooLong]),
        );

        self::assertSame(400, $client->getResponse()->getStatusCode());
        self::assertStringContainsString(
            (string) LabDataset::MAX_POISONED_REVIEW_LEN,
            (string) $client->getResponse()->getContent(),
            'el 400 por longitud debe decir el maximo (accionable)',
        );
    }
}
