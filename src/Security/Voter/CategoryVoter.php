<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Category;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * A category can only be managed by the account that owns it.
 *
 * @extends Voter<string, Category>
 */
final class CategoryVoter extends Voter
{
    public const MANAGE = 'CATEGORY_MANAGE';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return self::MANAGE === $attribute && $subject instanceof Category;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();

        return $user instanceof User && $subject->getUser()->getId()->equals($user->getId());
    }
}
