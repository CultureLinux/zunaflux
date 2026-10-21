<?php

declare(strict_types=1);

namespace App\Domain\Feed;

final class FeedDiscovery
{
    /** @return list<array{url: string, title: string}> */
    public function find(string $html, string $baseUrl): array
    {
        $previous = libxml_use_internal_errors(true);
        try {
            if (!preg_match('/<!DOCTYPE|<!ENTITY/i', $html)) {
                $xml = new \DOMDocument();
                if ($xml->loadXML($html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING) && $xml->documentElement instanceof \DOMElement) {
                    $root = $xml->documentElement;
                    if ('rss' === strtolower($root->localName)) return [['url' => $baseUrl, 'title' => 'Flux RSS']];
                    if ('feed' === $root->localName && 'http://www.w3.org/2005/Atom' === $root->namespaceURI) return [['url' => $baseUrl, 'title' => 'Flux Atom']];
                }
            }
            $document = new \DOMDocument();
            $document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
            $results = [];
            foreach ($document->getElementsByTagName('link') as $link) {
                $relations = preg_split('/\s+/', strtolower(trim($link->getAttribute('rel')))) ?: [];
                $type = strtolower(trim(explode(';', $link->getAttribute('type'), 2)[0]));
                if (!in_array('alternate', $relations, true) || !in_array($type, ['application/rss+xml', 'application/atom+xml'], true)) continue;
                $url = $this->resolve(trim($link->getAttribute('href')), $baseUrl);
                if (null === $url || isset($results[$url])) continue;
                $title = trim($link->getAttribute('title'));
                $results[$url] = ['url' => $url, 'title' => '' !== $title ? mb_substr($title, 0, 255) : ('application/rss+xml' === $type ? 'Flux RSS' : 'Flux Atom')];
                if (count($results) >= 20) break;
            }
            return array_values($results);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    public function resolve(string $href, string $base): ?string
    {
        if ('' === $href || str_starts_with($href, '#')) return null;
        $baseParts = parse_url($base);
        if (!is_array($baseParts) || !isset($baseParts['scheme'], $baseParts['host'])) return null;
        if (str_starts_with($href, '//')) $href = $baseParts['scheme'].':'.$href;
        if (null === parse_url($href, PHP_URL_SCHEME)) {
            $origin = $baseParts['scheme'].'://'.$baseParts['host'].(isset($baseParts['port']) ? ':'.$baseParts['port'] : '');
            $relative = parse_url($href);
            if (!is_array($relative)) return null;
            $path = $relative['path'] ?? '';
            if ('' === $path) $path = $baseParts['path'] ?? '/';
            elseif (!str_starts_with($path, '/')) $path = substr($baseParts['path'] ?? '/', 0, (int) strrpos($baseParts['path'] ?? '/', '/') + 1).$path;
            $segments = [];
            foreach (explode('/', $path) as $part) {
                if ('..' === $part) array_pop($segments);
                elseif ('' !== $part && '.' !== $part) $segments[] = $part;
            }
            $href = $origin.'/'.implode('/', $segments).(isset($relative['query']) ? '?'.$relative['query'] : '');
        }
        $parts = parse_url($href);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host']) || !in_array(strtolower($parts['scheme']), ['http', 'https'], true) || isset($parts['user']) || isset($parts['pass']) || strlen($href) > 2048 || !filter_var($href, FILTER_VALIDATE_URL)) return null;
        return $href;
    }
}
