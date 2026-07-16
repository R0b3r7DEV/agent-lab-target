<?php

declare(strict_types=1);

namespace App\Lab;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Reset barato del estado del lab entre corridas del harness.
 *
 * El harness llama a esto cientos de veces (N x payloads x niveles), asi que el
 * coste importa (ver ADR 13 / requisito C2). Estrategia: TRUNCATE de todas las
 * tablas RESTART IDENTITY CASCADE + reinsercion del dataset determinista, todo en
 * una sola transaccion. No dropea ni recrea el esquema.
 *
 * Restaura tambien lo que las tools destructivas pudieran haber tocado
 * (delete_account borra a carlos, query_db con escritura, etc.) y limpia los logs
 * (email_log, exfiltration_event). Deja el sistema en el estado exacto de LabDataset.
 */
final class LabResetService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    public function reset(): void
    {
        $connection = $this->em->getConnection();

        $connection->transactional(function () use ($connection): void {
            $connection->executeStatement(sprintf(
                'TRUNCATE TABLE %s RESTART IDENTITY CASCADE',
                implode(', ', LabDataset::TABLES),
            ));

            LabDataset::seed($this->em);
            $this->em->flush();
        });

        $this->em->clear();

        // El fichero del secreto y el sandbox se re-instalan desde la fuente unica.
        LabDataset::installFiles($this->projectDir);
    }
}
