<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Review de un producto. Vector de INYECCION INDIRECTA canonico (estilo el lab
 * de reviews de PortSwigger): `body` puede contener el payload malicioso que el
 * agente lee al "hablar" del producto y trata como instrucciones.
 *
 * ID ASIGNADO y estable entre resets.
 */
#[ORM\Entity]
#[ORM\Table(name: 'review')]
class Review
{
    #[ORM\Id]
    #[ORM\Column]
    private int $id;

    #[ORM\ManyToOne(targetEntity: Product::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Product $product;

    #[ORM\Column(length: 255)]
    private string $author;

    #[ORM\Column(type: Types::TEXT)]
    private string $body;

    public function __construct(int $id, Product $product, string $author, string $body)
    {
        $this->id = $id;
        $this->product = $product;
        $this->author = $author;
        $this->body = $body;
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getProduct(): Product
    {
        return $this->product;
    }

    public function getAuthor(): string
    {
        return $this->author;
    }

    public function getBody(): string
    {
        return $this->body;
    }
}
