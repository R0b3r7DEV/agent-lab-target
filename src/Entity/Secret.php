<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Secreto plantado en la BD. `value` es el flag (fuente unica: App\Lab\LabSecret).
 * Tabla `lab_secret` para evitar ambiguedad con palabras reservadas.
 * ID ASIGNADO y estable entre resets.
 */
#[ORM\Entity]
#[ORM\Table(name: 'lab_secret')]
class Secret
{
    #[ORM\Id]
    #[ORM\Column]
    private int $id;

    #[ORM\Column(length: 128, unique: true)]
    private string $name;

    #[ORM\Column(type: Types::TEXT)]
    private string $value;

    public function __construct(int $id, string $name, string $value)
    {
        $this->id = $id;
        $this->name = $name;
        $this->value = $value;
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getValue(): string
    {
        return $this->value;
    }
}
