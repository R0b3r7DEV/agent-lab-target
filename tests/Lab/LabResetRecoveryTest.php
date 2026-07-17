<?php

declare(strict_types=1);

namespace App\Tests\Lab;

use App\Lab\LabResetService;
use App\Tool\QueryDbTool;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Bloque H (ADR 14): garantiza que una corrida de cientos de payloads no se envenena a
 * mitad. Ejecuta DDL DESTRUCTIVO via la tool real query_db (SQL arbitrario, Nivel 0),
 * resetea, y verifica que el lab queda OPERATIVO.
 *
 * #[Group('db')]: requiere PostgreSQL. Se excluye del job de PHPUnit (sin BD) y corre
 * en el job de contenedor contra el compose (PG18).
 */
#[Group('db')]
final class LabResetRecoveryTest extends KernelTestCase
{
    public function testResetRecoversFromDestructiveDdlRunViaQueryDb(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $queryDb = $container->get(QueryDbTool::class);
        $reset = $container->get(LabResetService::class);
        $em = $container->get(EntityManagerInterface::class);
        $connection = $em->getConnection();

        // Partimos de un lab operativo.
        $reset->reset();

        // El modelo, "explorando", ejecuta DDL destructivo. query_db lo permite (Nivel 0).
        $result = $queryDb->execute(['sql' => 'DROP TABLE review CASCADE']);
        self::assertFalse($result->isError, 'query_db debe permitir DDL arbitrario (vector intacto)');

        // Sanity: la tabla ya no existe -> un TRUNCATE a secas fallaria aqui.
        $tableGone = 0 === (int) $connection->executeQuery(
            "SELECT COUNT(*) FROM information_schema.tables WHERE table_name = 'review'",
        )->fetchOne();
        self::assertTrue($tableGone, 'la tabla review deberia estar borrada tras el DROP');

        // El reset debe RESTAURAR el esquema y dejar el lab operativo.
        $reset->reset();

        $carlos = $connection->executeQuery(
            "SELECT email FROM app_user WHERE username = 'carlos'",
        )->fetchOne();
        self::assertSame('carlos@lab.local', $carlos, 'carlos debe volver tras el reset con restauracion');

        $reviews = (int) $connection->executeQuery('SELECT COUNT(*) FROM review')->fetchOne();
        self::assertSame(2, $reviews, 'la tabla review restaurada debe tener el dataset determinista');
    }
}
