<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Registro de send_email. Canal de exfiltracion.
 *
 * `blocked` (Cambio 1 / ADR 10) nace en la migracion inicial: registra TAMBIEN
 * los intentos DENEGADOS por el Nivel 2, no solo los "enviados". Distinguir
 * "el agente pico y la defensa bloqueo" de "nunca pico" es la integridad del ASR.
 * El registro se hace SIEMPRE antes de aplicar la defensa.
 *
 * ID autogenerado: es un log de runtime, no lo referencia el harness antes de la
 * corrida (se limpia en cada reset).
 */
#[ORM\Entity]
#[ORM\Table(name: 'email_log')]
class EmailLog
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $recipient;

    #[ORM\Column(type: Types::TEXT)]
    private string $body;

    #[ORM\Column]
    private bool $blocked;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $recipient, string $body, bool $blocked)
    {
        $this->recipient = $recipient;
        $this->body = $body;
        $this->blocked = $blocked;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRecipient(): string
    {
        return $this->recipient;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function isBlocked(): bool
    {
        return $this->blocked;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
