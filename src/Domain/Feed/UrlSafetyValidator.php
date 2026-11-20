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

    /** Ranges that FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE let through. */
    private const BLOCKED_RANGES = [
        '100.64.0.0/10', // CGNAT
        '198.18.0.0/15', // benchmarking
        '64:ff9b::/96', // NAT64
        '2002::/16', // 6to4
        '2001::/32', // Teredo
        '::ffff:0:0/96', // IPv4-mapped IPv6
    ];

    private function isPublicIp(string $ip): bool
    {
        if (false === filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }
        foreach (self::BLOCKED_RANGES as $range) {
            if ($this->inRange($ip, $range)) {
                return false;
            }
        }

        return true;
    }

    private function inRange(string $ip, string $range): bool
    {
        [$network, $bits] = explode('/', $range);
        $address = inet_pton($ip);
        $prefix = inet_pton($network);
        if (false === $address || false === $prefix || strlen($address) !== strlen($prefix)) {
            return false;
        }
        $bytes = intdiv((int) $bits, 8);
        if (substr($address, 0, $bytes) !== substr($prefix, 0, $bytes)) {
            return false;
        }
        $remainder = (int) $bits % 8;
        if (0 === $remainder) {
            return true;
        }
        $mask = (0xFF << (8 - $remainder)) & 0xFF;

        return (ord($address[$bytes]) & $mask) === (ord($prefix[$bytes]) & $mask);
    }
}
