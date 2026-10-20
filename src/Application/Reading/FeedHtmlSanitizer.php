<?php

declare(strict_types=1);

namespace App\Application\Reading;

/** Renders untrusted feed content using only a small, resource-free set of HTML elements. */
final class FeedHtmlSanitizer
{
    private const ALLOWED = ['p', 'br', 'strong', 'b', 'em', 'i', 's', 'blockquote', 'ul', 'ol', 'li', 'h2', 'h3', 'h4', 'pre', 'code', 'a', 'hr'];
    private const DROP = ['script', 'style', 'svg', 'math', 'iframe', 'object', 'embed', 'form', 'template', 'noscript'];

    public function sanitize(string $html, ?callable $imagePath = null): string
    {
        $document = new \DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        try {
            $document->loadHTML('<?xml encoding="UTF-8"><html><body>'.$html.'</body></html>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
            $body = $document->getElementsByTagName('body')->item(0);
            $urls = $this->urlsFromDocument($document);
            return null === $body ? '' : $this->children($body, $urls, $imagePath);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    /** @return list<string> */
    public function imageUrls(string $html): array
    {
        $document = new \DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        try {
            $document->loadHTML('<?xml encoding="UTF-8"><html><body>'.$html.'</body></html>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
            return $this->urlsFromDocument($document);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    /** @return list<string> */
    private function urlsFromDocument(\DOMDocument $document): array
    {
        $urls = [];
        foreach ($document->getElementsByTagName('img') as $image) {
            $url = trim($image->getAttribute('src'));
            $parts = parse_url($url);
            if (filter_var($url, FILTER_VALIDATE_URL) && is_array($parts) && in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) && !isset($parts['user']) && !isset($parts['pass'])) {
                $urls[] = $url;
            }
        }
        return $urls;
    }

    /** @param list<string> $urls */
    private function children(\DOMNode $node, array $urls, ?callable $imagePath): string
    {
        $result = '';
        foreach ($node->childNodes as $child) {
            if ($child instanceof \DOMText) {
                $result .= htmlspecialchars($child->data, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
                continue;
            }
            if (!$child instanceof \DOMElement) {
                continue;
            }
            $tag = strtolower($child->tagName);
            if ('img' === $tag) {
                $index = array_search(trim($child->getAttribute('src')), $urls, true);
                if (false !== $index && null !== $imagePath) {
                    $src = htmlspecialchars($imagePath($index), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
                    $alt = htmlspecialchars($child->getAttribute('alt'), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
                    $result .= '<img src="'.$src.'" alt="'.$alt.'" loading="lazy" decoding="async">';
                }
                continue;
            }
            if (in_array($tag, self::DROP, true)) {
                continue;
            }
            if (!in_array($tag, self::ALLOWED, true)) {
                $result .= $this->children($child, $urls, $imagePath);
                continue;
            }
            $attributes = '';
            if ('a' === $tag) {
                $url = trim($child->getAttribute('href'));
                if (filter_var($url, FILTER_VALIDATE_URL) && in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)) {
                    $attributes = ' href="'.htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8').'" rel="noopener noreferrer" target="_blank"';
                }
            }
            $result .= '<'.$tag.$attributes.'>';
            if (!in_array($tag, ['br', 'hr'], true)) {
                $result .= $this->children($child, $urls, $imagePath).'</'.$tag.'>';
            }
        }
        return $result;
    }
}
