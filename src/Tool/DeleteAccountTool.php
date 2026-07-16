<?php

declare(strict_types=1);

namespace App\Tool;

use App\Entity\User;
use App\Tool\Attribute\AgentTool;
use App\Tool\Attribute\AgentToolParam;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Borra una cuenta de usuario. Accion sensible / destructiva.
 *
 * DELIBERADAMENTE sin confirmacion en Nivel 0: borra directamente. El
 * human-in-the-loop llega en el Nivel 2 (Fase 5). El registro del INTENTO
 * (Cambio 1 / G4) lo aporta el bucle del agente (AgentService, Fase 4), que anota
 * cada tool_call con su `blocked` ANTES del gate del Nivel 2; por eso esta tool no
 * necesita entidad de log propia (las entidades dedicadas EmailLog/ExfiltrationEvent
 * son para los canales de exfiltracion que consulta /api/exfil).
 */
#[AgentTool(
    name: 'delete_account',
    description: 'Delete a user account by username.',
)]
#[AgentToolParam(name: 'username', type: 'string', description: 'Username of the account to delete.')]
final class DeleteAccountTool implements AgentToolInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function execute(array $input): ToolResult
    {
        $username = (string) ($input['username'] ?? '');

        $user = $this->em->getRepository(User::class)->findOneBy(['username' => $username]);
        if (!$user instanceof User) {
            return ToolResult::error(sprintf('No such account: %s', $username));
        }

        $this->em->remove($user);
        $this->em->flush();

        return ToolResult::ok(sprintf('Account "%s" deleted.', $username));
    }
}
