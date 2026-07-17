<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Log DURABLE de toda invocacion de herramienta (Bloque I, ADR 15).
 *
 * Invariante (no negociable): TODA invocacion se registra aqui, de forma durable
 * (en BD, sobrevive a un timeout del request), con `blocked`, ANTES del gate. El
 * `tool_calls` de /api/chat es una PROYECCION de estas filas, no una contabilidad
 * paralela — una sola fuente de verdad para el hecho "se invoco la tool X con
 * blocked=Y" (Cambio 1 + Cambio 5).
 *
 * `/api/exfil` (Fase 6) es una proyeccion sobre esta tabla filtrada a las tools de
 * exfiltracion (fetch_url, send_email): no hay entidades especializadas que dupliquen
 * el hecho; los campos de exfiltracion se derivan del `input` guardado.
 */
#[ORM\Entity]
#[ORM\Table(name: 'tool_invocation')]
class ToolInvocation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 64)]
    private string $tool;

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON)]
    private array $input;

    #[ORM\Column]
    private bool $blocked;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $resultSummary = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /**
     * @param array<string, mixed> $input
     */
    public function __construct(string $tool, array $input, bool $blocked)
    {
        $this->tool = $tool;
        $this->input = $input;
        $this->blocked = $blocked;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTool(): string
    {
        return $this->tool;
    }

    /**
     * @return array<string, mixed>
     */
    public function getInput(): array
    {
        return $this->input;
    }

    public function isBlocked(): bool
    {
        return $this->blocked;
    }

    public function setBlocked(bool $blocked): void
    {
        $this->blocked = $blocked;
    }

    public function getResultSummary(): ?string
    {
        return $this->resultSummary;
    }

    public function setResultSummary(?string $resultSummary): void
    {
        $this->resultSummary = $resultSummary;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
