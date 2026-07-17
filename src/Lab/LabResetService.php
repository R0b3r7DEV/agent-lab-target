<?php

declare(strict_types=1);

namespace App\Lab;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Reset barato del estado del lab entre corridas del harness, ROBUSTO ante dano de
 * esquema (ADR 14).
 *
 * El problema: `query_db` acepta SQL arbitrario, DDL incluido. Un payload (o el
 * propio modelo "explorando") puede ejecutar `DROP TABLE` / `ALTER`, y un `TRUNCATE`
 * a secas fallaria a partir de ahi, envenenando en silencio todas las mediciones
 * posteriores de una corrida desatendida de cientos de payloads.
 *
 * Estrategia (opcion (a) del ADR 14): via rapida + restauracion.
 *  - Camino feliz (99%): TRUNCATE RESTART IDENTITY CASCADE + reseed en una transaccion.
 *    El TRUNCATE es a la vez el reset y la comprobacion barata de deriva: si una tabla
 *    falta (DROP), lanza; si una columna falta (ALTER), lanza el reseed.
 *  - Solo tras dano de DDL: restauracion completa (DROP SCHEMA public CASCADE +
 *    recrear el esquema desde el mapping) + reseed. Se paga el precio (mas caro) solo
 *    cuando toca, no en cada reset.
 *
 * Mantiene la superficie de ataque intacta: query_db sigue aceptando DDL (el vector
 * no se recorta), pero el reset garantiza que la siguiente medicion parte de un lab
 * operativo.
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
        try {
            $this->fastReset();
        } catch (\Throwable) {
            $this->fullRestore();
        }

        $this->em->clear();
        LabDataset::installFiles($this->projectDir);
    }

    /**
     * Camino feliz: TRUNCATE + reseed en una transaccion. Lanza si el esquema derivo.
     */
    private function fastReset(): void
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
    }

    /**
     * Restauracion completa tras dano de DDL: recrea el schema public entero desde el
     * mapping de Doctrine (equivalente a la migracion; schema:validate lo garantiza) y
     * reinserta el dataset.
     */
    private function fullRestore(): void
    {
        // El fallo pudo dejar el UoW y/o una transaccion a medias.
        $this->em->clear();
        $connection = $this->em->getConnection();
        if ($connection->isTransactionActive()) {
            $connection->rollBack();
        }

        // Nuclear pero robusto ante cualquier dano (DROP, ALTER, tablas extra creadas
        // por el atacante): se recrea el esquema public completo.
        $connection->executeStatement('DROP SCHEMA public CASCADE');
        $connection->executeStatement('CREATE SCHEMA public');

        $schemaTool = new SchemaTool($this->em);
        $schemaTool->createSchema($this->em->getMetadataFactory()->getAllMetadata());

        LabDataset::seed($this->em);
        $this->em->flush();
    }
}
