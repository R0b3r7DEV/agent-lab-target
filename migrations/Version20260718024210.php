<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Fase 5 (N2): anade `blocked_reason` a tool_invocation. Codigo estable de la capa que
 * bloqueo la llamada (confirmation_denied | egress_allowlist), proyectado a
 * tool_calls[].blocked_reason para que el harness distinga "no intentado" de "bloqueado
 * por una capa concreta". Ver ADR 05.
 */
final class Version20260718024210 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add blocked_reason to tool_invocation (Fase 5, N2)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tool_invocation ADD blocked_reason VARCHAR(64) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tool_invocation DROP blocked_reason');
    }
}
