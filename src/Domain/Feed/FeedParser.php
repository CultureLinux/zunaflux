<?php

declare(strict_types=1);

namespace App\Domain\Feed;

final class FeedParser
{
    public function parse(string $xml): ParsedFeed
    {
        // A plain DOCTYPE is legitimate (RSS 0.91 feeds declare one) and harmless with
        // LIBXML_NONET: only entity declarations are refused. The check is repeated on the
        // parsed document because a UTF-16 payload evades the textual pre-check.
        if (preg_match('/<!ENTITY/i', $xml)) {
            throw new \InvalidArgumentException('Feed XML must not contain a DOCTYPE with entities.');
        }
        $previous = libxml_use_internal_errors(true);
        try {
            $document = new \DOMDocument();
            if (!$document->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT)) {
                throw new \InvalidArgumentException('The feed is not valid XML.');
            }
            if (null !== $document->doctype && $document->doctype->entities->length > 0) {
                throw new \InvalidArgumentException('Feed XML must not contain a DOCTYPE with entities.');
            }
            $root = $document->documentElement;
            if (!$root instanceof \DOMElement) {
                throw new \InvalidArgumentException('The feed is empty.');
            }
            $xpath = new \DOMXPath($document);

            return 'feed' === $root->localName
                ? $this->parseAtom($xpath)
                : $this->parseRss($xpath);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private function parseAtom(\DOMXPath $xpath): ParsedFeed
    {
        $items = [];
        foreach ($xpath->query('/*[local-name()="feed"]/*[local-name()="entry"]') ?: [] as $entry) {
            $id = $this->text($xpath, './*[local-name()="id"]', $entry);
            $url = $this->attribute($xpath, './*[local-name()="link"][@rel="alternate" or not(@rel)][1]', 'href', $entry);
            $title = $this->text($xpath, './*[local-name()="title"]', $entry) ?? '(sans titre)';
            $identity = $id ?: ($url ? hash('sha256', $url) : hash('sha256', $title.$this->text($xpath, './*[local-name()="updated"]', $entry)));
            $items[] = new ParsedFeedItem($identity, $title, $url, $this->text($xpath, './*[local-name()="summary"]', $entry), $this->text($xpath, './*[local-name()="content"]', $entry), $this->date($this->text($xpath, './*[local-name()="published" or local-name()="updated"]', $entry)));
        }
        return new ParsedFeed($this->text($xpath, '/*[local-name()="feed"]/*[local-name()="title"]'), $this->attribute($xpath, '/*[local-name()="feed"]/*[local-name()="link"][@rel="alternate"][1]', 'href'), $items);
    }

    private function parseRss(\DOMXPath $xpath): ParsedFeed
    {
        $items = [];
        foreach ($xpath->query('/*[local-name()="rss" or local-name()="RDF"]/*[local-name()="channel"]/*[local-name()="item"] | /*[local-name()="RDF"]/*[local-name()="item"]') ?: [] as $item) {
            $url = $this->text($xpath, './*[local-name()="link" and namespace-uri()!="http://www.w3.org/2005/Atom"]', $item);
            $guid = $this->text($xpath, './*[local-name()="guid"]', $item);
            $title = $this->text($xpath, './*[local-name()="title"]', $item) ?? '(sans titre)';
            $identity = $guid ?: ($url ? hash('sha256', $url) : hash('sha256', $title.$this->text($xpath, './*[local-name()="pubDate"]', $item)));
            $items[] = new ParsedFeedItem($identity, $title, $url, $this->text($xpath, './*[local-name()="description"]', $item), $this->text($xpath, './*[local-name()="encoded"]', $item), $this->date($this->text($xpath, './*[local-name()="pubDate" or local-name()="date"]', $item)));
        }
        $channel = '/*[local-name()="rss" or local-name()="RDF"]/*[local-name()="channel"]';

        return new ParsedFeed($this->text($xpath, $channel.'/*[local-name()="title"]'), $this->text($xpath, $channel.'/*[local-name()="link" and namespace-uri()!="http://www.w3.org/2005/Atom"]'), $items);
    }

    private function text(\DOMXPath $xpath, string $query, ?\DOMNode $context = null): ?string { $node = $xpath->query($query, $context)->item(0); $value = $node ? trim($node->textContent) : ''; return '' === $value ? null : $value; }
    private function attribute(\DOMXPath $xpath, string $query, string $attribute, ?\DOMNode $context = null): ?string { $node = $xpath->query($query, $context)->item(0); $value = $node instanceof \DOMElement ? trim($node->getAttribute($attribute)) : ''; return '' === $value ? null : $value; }
    private function date(?string $value): ?\DateTimeImmutable { if (null === $value) return null; try { return new \DateTimeImmutable($value); } catch (\Exception) { return null; } }
}
