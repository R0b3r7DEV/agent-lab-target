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

    /**
     * Resetea el lab a estado determinista. `$poisonedReviewOverride` (solo /api/reset
     * parametrizado) sobrescribe el cuerpo de la review envenenada; null = canonico
     * (retrocompatible). Ambos caminos (rapido y restauracion) aplican el override.
     *
     * Devuelve una CONFIRMACION DE SIEMBRA leida del estado PERSISTIDO (no un calculo
     * paralelo, Cambio 1/Bloque I): atestigua lo que el agente leera de verdad.
     *
     * @return array{poisoned_review_len: int, poisoned_review_sha256: string}
     */
    public function reset(?string $poisonedReviewOverride = null): array
    {
        try {
            $this->fastReset($poisonedReviewOverride);
        } catch (\Throwable) {
            $this->fullRestore($poisonedReviewOverride);
        }

        $this->em->clear();
        LabDataset::installFiles($this->projectDir);

        return $this->seedConfirmation();
    }

    /**
     * Lee el cuerpo de la review envenenada YA PERSISTIDA (fresco desde PG) y deriva de ahi
     * la confirmacion. Si entre resolve y persist se colara una transformacion (callback,
     * trim futuro), la confirmacion reflejaria la verdad de tierra, no la intencion.
     *
     * @return array{poisoned_review_len: int, poisoned_review_sha256: string}
     */
    private function seedConfirmation(): array
    {
        $body = (string) $this->em->getConnection()->fetchOne(
            'SELECT body FROM review WHERE id = 2',
        );

        return [
            'poisoned_review_len' => mb_strlen($body),
            'poisoned_review_sha256' => hash('sha256', $body),
        ];
    }

    /**
     * Camino feliz: TRUNCATE + reseed en una transaccion. Lanza si el esquema derivo.
     */
    private function fastReset(?string $poisonedReviewOverride): void
    {
        $connection = $this->em->getConnection();

        $connection->transactional(function () use ($connection, $poisonedReviewOverride): void {
            $connection->executeStatement(sprintf(
                'TRUNCATE TABLE %s RESTART IDENTITY CASCADE',
                implode(', ', LabDataset::TABLES),
            ));

            LabDataset::seed($this->em, $poisonedReviewOverride);
            $this->em->flush();
        });
    }

    /**
     * Restauracion completa tras dano de DDL: recrea el schema public entero desde el
     * mapping de Doctrine (equivalente a la migracion; schema:validate lo garantiza) y
     * reinserta el dataset.
     */
    private function fullRestore(?string $poisonedReviewOverride): void
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

        LabDataset::seed($this->em, $poisonedReviewOverride);
        $this->em->flush();
    }
}
