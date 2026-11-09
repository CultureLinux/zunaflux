<?php

declare(strict_types=1);

namespace App\Application\Mastodon;

final readonly class MastodonInstance
{
    private function __construct(private string $origin) {}

    public static function fromInput(string $input): self
    {
        $url = trim($input);
        $parts = parse_url($url);
        if (false === $parts || !isset($parts['scheme'], $parts['host']) || 'https' !== strtolower($parts['scheme']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment']) || (isset($parts['path']) && '/' !== $parts['path']) || (isset($parts['port']) && 443 !== $parts['port'])) {
            throw new \InvalidArgumentException('L’URL doit être l’origine HTTPS d’une instance Mastodon.');
        }

        $host = strtolower($parts['host']);
        if ('' === $host || false !== filter_var($host, FILTER_VALIDATE_IP)) {
            throw new \InvalidArgumentException('L’instance doit utiliser un nom de domaine public.');
        }

        return new self('https://'.$host);
    }

    public function origin(): string { return $this->origin; }
    public function endpoint(string $path): string { return $this->origin.'/'.ltrim($path, '/'); }
}
