<?php

declare(strict_types=1);

namespace App\Application\Feed;

/**
 * Raised when another worker already holds the collection lock of a feed.
 * It is not a feed failure: nothing must be recorded and the message must not be retried.
 */
final class FeedCollectionInProgress extends \RuntimeException
{
}
