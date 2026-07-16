<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Registro de egress de fetch_url (la "trampa" de exfiltracion, consultable en
 * GET /api/exfil — Fase 6).
 *
 * `blocked` (Cambio 1 / ADR 10) nace en la migracion inicial: fetch_url registra
 * SIEMPRE el egress ANTES de aplicar EgressAllowlist; `blocked` refleja si la
 * allowlist lo corto. Sin esto, en el Nivel 3 no se distingue "el agente pico y
 * la defensa bloqueo" (defensa funciona) de "el agente nunca pico" (inyeccion
 * fallo), y se infla artificialmente la efectividad del Nivel 3.
 *
 * ID autogenerado: log de runtime, se limpia en cada reset.
 */
#[ORM\Entity]
#[ORM\Table(name: 'exfiltration_event')]
class ExfiltrationEvent
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 64)]
    private string $tool;

    #[ORM\Column(length: 255)]
    private string $domain;

    #[ORM\Column(type: Types::TEXT)]
    private string $url;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $query;

    #[ORM\Column]
    private bool $blocked;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $tool, string $domain, string $url, ?string $query, bool $blocked)
    {
        $this->tool = $tool;
        $this->domain = $domain;
        $this->url = $url;
        $this->query = $query;
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

    public function getDomain(): string
    {
        return $this->domain;
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    public function getQuery(): ?string
    {
        return $this->query;
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
