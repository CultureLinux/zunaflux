<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Article;
use App\Entity\Subscription;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * An article is readable by the accounts that follow its source with an enabled subscription.
 *
 * @extends Voter<string, Article>
 */
final class ArticleVoter extends Voter
{
    public const READ = 'ARTICLE_READ';

    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return self::READ === $attribute && $subject instanceof Article;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        return $this->entityManager->getRepository(Subscription::class)->count([
            'user' => $user,
            'feed' => $subject->getFeed(),
            'enabled' => true,
        ]) > 0;
    }
}
