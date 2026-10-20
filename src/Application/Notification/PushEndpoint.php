<?php

declare(strict_types=1);

namespace App\Application\Notification;

final class PushEndpoint
{
    public static function isAllowed(string $endpoint): bool
    {
        $parts = parse_url($endpoint);

        return false !== $parts
            && 'https' === ($parts['scheme'] ?? null)
            && in_array($parts['host'] ?? null, ['fcm.googleapis.com', 'updates.push.services.mozilla.com'], true)
            && !isset($parts['user']) && !isset($parts['pass'])
            && (!isset($parts['port']) || 443 === $parts['port'])
            && !isset($parts['fragment'])
            && strlen($endpoint) <= 2048;
    }
}
