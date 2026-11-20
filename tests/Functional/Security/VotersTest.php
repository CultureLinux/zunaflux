<?php

declare(strict_types=1);

namespace App\Tests\Functional\Security;

use App\Entity\Category;
use App\Entity\User;
use App\Security\Voter\ArticleVoter;
use App\Security\Voter\CategoryVoter;
use App\Security\Voter\SubscriptionVoter;
use App\Tests\Support\RollbackKernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\NullToken;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

final class VotersTest extends RollbackKernelTestCase
{
    public function testOnlyTheOwnerMayManageASubscriptionAndACategory(): void
    {
        $owner = $this->user();
        $stranger = $this->user();
        $subscription = $this->subscribe($owner, $this->feed());
        $category = new Category($owner, 'Mine');
        $this->em->persist($category);
        $this->em->flush();
        $subscriptions = new SubscriptionVoter();
        $categories = new CategoryVoter();

        self::assertSame(VoterInterface::ACCESS_GRANTED, $subscriptions->vote($this->token($owner), $subscription, [SubscriptionVoter::MANAGE]));
        self::assertSame(VoterInterface::ACCESS_DENIED, $subscriptions->vote($this->token($stranger), $subscription, [SubscriptionVoter::MANAGE]));
        self::assertSame(VoterInterface::ACCESS_DENIED, $subscriptions->vote(new NullToken(), $subscription, [SubscriptionVoter::MANAGE]));
        self::assertSame(VoterInterface::ACCESS_GRANTED, $categories->vote($this->token($owner), $category, [CategoryVoter::MANAGE]));
        self::assertSame(VoterInterface::ACCESS_DENIED, $categories->vote($this->token($stranger), $category, [CategoryVoter::MANAGE]));
    }

    public function testAnArticleIsReadableOnlyThroughAnEnabledSubscriptionToItsSource(): void
    {
        $reader = $this->user();
        $stranger = $this->user();
        $disabledReader = $this->user();
        $feed = $this->feed();
        $article = $this->article($feed);
        $this->subscribe($reader, $feed);
        $this->subscribe($disabledReader, $feed)->disable();
        $this->em->flush();
        $voter = new ArticleVoter($this->em);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote($this->token($reader), $article, [ArticleVoter::READ]));
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($this->token($stranger), $article, [ArticleVoter::READ]));
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($this->token($disabledReader), $article, [ArticleVoter::READ]));
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote(new NullToken(), $article, [ArticleVoter::READ]));
    }

    public function testTheVotersAbstainFromWhatIsNotTheirs(): void
    {
        $user = $this->user();
        $subscription = $this->subscribe($user, $this->feed());
        $this->em->flush();
        $token = $this->token($user);

        self::assertSame(VoterInterface::ACCESS_ABSTAIN, (new SubscriptionVoter())->vote($token, $subscription, ['SOMETHING_ELSE']));
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, (new SubscriptionVoter())->vote($token, $user, [SubscriptionVoter::MANAGE]));
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, (new CategoryVoter())->vote($token, $subscription, [CategoryVoter::MANAGE]));
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, (new ArticleVoter($this->em))->vote($token, $subscription, [ArticleVoter::READ]));
    }

    private function token(User $user): UsernamePasswordToken
    {
        return new UsernamePasswordToken($user, 'main', $user->getRoles());
    }
}
