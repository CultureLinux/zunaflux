<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'registration_settings')]
final class RegistrationSettings
{
    #[ORM\Id]
    #[ORM\Column]
    private int $id = 1;

    #[ORM\Column(name: 'account_limit', nullable: true)]
    private ?int $accountLimit = null;
}
