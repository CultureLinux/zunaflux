<?php

declare(strict_types=1);

namespace App\Domain\Feed;

final readonly class DiscoveryFetcher
{
    public function __construct(private UrlSafetyValidator $validator) {}

    /** @return array{url: string, body: string} */
    public function fetch(string $url): array
    {
        for ($redirects = 0; $redirects <= 3; ++$redirects) {
            $ips = $this->validator->assertAllowed($url);
            foreach ($ips as $ip) {
                if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) throw new \InvalidArgumentException('Adresse non prise en charge.');
                $number = (int) sprintf('%u', ip2long($ip));
                if (($number >= 1681915904 && $number <= 1686110207) || ($number >= 3323068416 && $number <= 3323199487)) {
                    throw new \InvalidArgumentException('Adresse réservée.');
                }
            }
            $parts = parse_url($url);
            $host = trim($parts['host'], '[]');
            $port = $parts['port'] ?? ('https' === strtolower($parts['scheme']) ? 443 : 80);
            $body = '';
            $location = null;
            $tooLarge = false;
            if (!function_exists('curl_init')) throw new \RuntimeException('L’extension PHP cURL est indisponible sur le serveur.');
            $curl = curl_init($url);
            if (false === $curl) throw new \RuntimeException('Connexion impossible.');
            try {
                curl_setopt_array($curl, [
                    CURLOPT_PROXY => '', CURLOPT_FOLLOWLOCATION => false,
                    CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                    CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 15,
                    CURLOPT_RESOLVE => [$host.':'.$port.':'.implode(',', $ips)],
                    CURLOPT_HTTPHEADER => ['Accept: text/html, application/rss+xml, application/atom+xml, application/xml;q=0.9', 'User-Agent: Zunaflux/1.0'],
                    CURLOPT_HEADERFUNCTION => static function ($handle, string $header) use (&$location): int {
                        if (preg_match('/^location:\s*(.*?)\s*$/i', $header, $match)) $location = $match[1];
                        return strlen($header);
                    },
                    CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body, &$tooLarge): int {
                        if (strlen($body) + strlen($chunk) > 2_000_000) { $tooLarge = true; return 0; }
                        $body .= $chunk;
                        return strlen($chunk);
                    },
                ]);
                $ok = curl_exec($curl);
                $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
                if ($tooLarge) throw new \RuntimeException('La page dépasse la limite de téléchargement de 2 Mo.');
                if (false === $ok) throw new \RuntimeException('La connexion au site a échoué (code cURL '.curl_errno($curl).').');
            } finally {
                curl_close($curl);
            }
            if ($status >= 300 && $status < 400 && null !== $location) {
                $url = (new FeedDiscovery())->resolve($location, $url) ?? throw new \RuntimeException('Redirection invalide.');
                continue;
            }
            if (403 === $status) throw new \RuntimeException('Le site refuse l’accès (HTTP 403). Il utilise peut-être une protection contre les robots.', 403);
            if (200 !== $status) throw new \RuntimeException('Le site a répondu avec le code HTTP '.$status.'.');
            return ['url' => $url, 'body' => $body];
        }
        throw new \RuntimeException('Trop de redirections.');
    }
}
