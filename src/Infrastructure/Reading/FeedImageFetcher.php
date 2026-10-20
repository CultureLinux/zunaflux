<?php

declare(strict_types=1);

namespace App\Infrastructure\Reading;

use App\Domain\Feed\UrlSafetyValidator;

final readonly class FeedImageFetcher
{
    private const MAX_BYTES = 5_242_880;

    public function __construct(private UrlSafetyValidator $validator) {}

    /** @return array{body: string, type: string} */
    public function fetch(string $url): array
    {
        $ips = $this->validator->assertAllowed($url);
        foreach ($ips as $ip) {
            // Restrict this relay to public IPv4; also exclude shared-address and benchmark networks.
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) throw new \InvalidArgumentException('Unsupported address.');
            $number = (int) sprintf('%u', ip2long($ip));
            if (($number >= 1681915904 && $number <= 1686110207) || ($number >= 3323068416 && $number <= 3323199487)) {
                throw new \InvalidArgumentException('Reserved address.');
            }
        }
        $parts = parse_url($url);
        $host = $parts['host'];
        $port = $parts['port'] ?? ('https' === strtolower($parts['scheme']) ? 443 : 80);
        $addresses = array_map(static fn (string $ip): string => str_contains($ip, ':') ? '['.$ip.']' : $ip, $ips);
        $body = '';
        $curl = curl_init($url);
        if (false === $curl) throw new \RuntimeException('Image unavailable.');
        try {
            curl_setopt_array($curl, [
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_PROXY => '',
                CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => 15,
                CURLOPT_RESOLVE => [trim($host, '[]').':'.$port.':'.implode(',', $addresses)],
                CURLOPT_HTTPHEADER => ['Accept: image/png,image/jpeg,image/gif,image/webp'],
                CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body): int {
                    if (strlen($body) + strlen($chunk) > self::MAX_BYTES) return 0;
                    $body .= $chunk;
                    return strlen($chunk);
                },
            ]);
            $result = curl_exec($curl);
            $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            if (false === $result || 200 !== $status) throw new \RuntimeException('Image unavailable.');
        } finally {
            curl_close($curl);
        }
        $info = @getimagesizefromstring($body);
        $type = $info['mime'] ?? '';
        if (!in_array($type, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true) || ($info[0] * $info[1]) > 40_000_000) {
            throw new \RuntimeException('Unsupported image.');
        }
        return ['body' => $body, 'type' => $type];
    }
}
