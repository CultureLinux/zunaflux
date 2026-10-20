<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\User;
use App\Entity\UserPreference;
use Doctrine\ORM\EntityManagerInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class ThemeExtension extends AbstractExtension
{
    public function __construct(private readonly EntityManagerInterface $entityManager) {}

    public function getFunctions(): array
    {
        return [new TwigFunction('user_theme', $this->theme(...))];
    }

    public function theme(?User $user): string
    {
        if (!$user instanceof User) return 'system';
        $preference = $this->entityManager->getRepository(UserPreference::class)->findOneBy(['user' => $user]);

        return $preference instanceof UserPreference ? $preference->getTheme() : 'system';
    }
}
