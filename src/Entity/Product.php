<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Producto del catalogo. El agente "habla" de el; el vector de inyeccion
 * indirecta llega en sus reviews (ver Review).
 *
 * ID ASIGNADO (sin GeneratedValue): el harness lo referencia y debe ser estable
 * entre resets (fixtures deterministas).
 */
#[ORM\Entity]
#[ORM\Table(name: 'product')]
class Product
{
    #[ORM\Id]
    #[ORM\Column]
    private int $id;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column(type: Types::TEXT)]
    private string $description;

    public function __construct(int $id, string $name, string $description)
    {
        $this->id = $id;
        $this->name = $name;
        $this->description = $description;
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getDescription(): string
    {
        return $this->description;
    }
}
