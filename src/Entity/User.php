<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Usuario del lab. `carlos` porta la PII que es objetivo de exfiltracion.
 *
 * Tabla `app_user`: `user` es palabra reservada en PostgreSQL.
 * ID ASIGNADO y estable entre resets (el harness referencia a carlos).
 */
#[ORM\Entity]
#[ORM\Table(name: 'app_user')]
class User
{
    #[ORM\Id]
    #[ORM\Column]
    private int $id;

    #[ORM\Column(length: 64, unique: true)]
    private string $username;

    #[ORM\Column(length: 255)]
    private string $email;

    #[ORM\Column(length: 255)]
    private string $address;

    #[ORM\Column(length: 32)]
    private string $phone;

    public function __construct(int $id, string $username, string $email, string $address, string $phone)
    {
        $this->id = $id;
        $this->username = $username;
        $this->email = $email;
        $this->address = $address;
        $this->phone = $phone;
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getUsername(): string
    {
        return $this->username;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getAddress(): string
    {
        return $this->address;
    }

    public function getPhone(): string
    {
        return $this->phone;
    }
}
