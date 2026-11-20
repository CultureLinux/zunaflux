<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Exception;

use App\Application\Exception\AccountStillActive;
use App\Application\Exception\AlreadySubscribed;
use App\Application\Exception\ArticleInaccessible;
use App\Application\Exception\BusinessRuleViolation;
use App\Application\Exception\CategoryAlreadyExists;
use App\Application\Exception\InvalidFeedAddress;
use App\Application\Exception\InvalidOpmlFile;
use App\Application\Exception\SubscriptionLimitReached;
use App\Application\Exception\TranslatableException;
use App\Application\Exception\UserInputError;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TranslatableExceptionTest extends TestCase
{
    /** @return iterable<string, array{TranslatableException, class-string<\Throwable>}> */
    public static function exceptions(): iterable
    {
        yield 'limit' => [new SubscriptionLimitReached(), \InvalidArgumentException::class];
        yield 'already subscribed' => [new AlreadySubscribed(), \InvalidArgumentException::class];
        yield 'category exists' => [new CategoryAlreadyExists(), \InvalidArgumentException::class];
        yield 'bad address' => [new InvalidFeedAddress(), \InvalidArgumentException::class];
        yield 'active account' => [new AccountStillActive(), \DomainException::class];
        yield 'inaccessible' => [new ArticleInaccessible(), \DomainException::class];
        yield 'user input' => [new UserInputError('Saisissez un entier positif.'), \InvalidArgumentException::class];
        yield 'opml' => [new InvalidOpmlFile('Le fichier n’est pas un OPML valide.'), \InvalidArgumentException::class];
        yield 'rule' => [new BusinessRuleViolation('La limite est définie par l’environnement.'), \DomainException::class];
    }

    /** @param class-string<\Throwable> $parent */
    #[DataProvider('exceptions')]
    public function testTheMessageIsTheTranslationKeyAndTheSplParentIsKept(TranslatableException $exception, string $parent): void
    {
        self::assertSame($exception->getMessage(), $exception->getTranslationKey());
        self::assertInstanceOf($parent, $exception);
        self::assertSame([], $exception->getTranslationParameters());
    }

    public function testParametersAndThePreviousExceptionAreCarried(): void
    {
        $previous = new \RuntimeException('cause');
        $error = new UserInputError('%count% article(s) refusé(s).', ['%count%' => 3], $previous);

        self::assertSame(['%count%' => 3], $error->getTranslationParameters());
        self::assertSame($previous, $error->getPrevious());
    }
}
