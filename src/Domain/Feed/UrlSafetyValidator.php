<?php

declare(strict_types=1);

namespace App\Domain\Feed;

final class UrlSafetyValidator
{
    /** @return list<string> */
    public function assertAllowed(string $url): array
    {
        $parts = parse_url($url);
        if (false === $parts || !isset($parts['scheme'], $parts['host']) || !in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            throw new \InvalidArgumentException('The URL must be an absolute HTTP(S) URL.');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new \InvalidArgumentException('The URL must not contain credentials.');
        }
        if (isset($parts['port']) && !in_array($parts['port'], [80, 443], true)) {
            throw new \InvalidArgumentException('The URL uses a forbidden port.');
        }

        $host = trim($parts['host'], '[]');
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);
        if ([] === $ips) {
            throw new \InvalidArgumentException('The URL host cannot be resolved.');
        }
        foreach ($ips as $ip) {
            if (!$this->isPublicIp($ip)) {
                throw new \InvalidArgumentException('The URL resolves to a private or reserved network.');
            }
        }

        return $ips;
    }

    private function isPublicIp(string $ip): bool
    {
        return false !== filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    }
}
