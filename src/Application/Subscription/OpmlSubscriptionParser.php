<?php

declare(strict_types=1);

namespace App\Application\Subscription;

final class OpmlSubscriptionParser
{
    /** @return list<ImportedSubscription> */
    public function parse(string $xml): array
    {
        if (preg_match('/<!DOCTYPE|<!ENTITY/i', $xml)) {
            throw new \InvalidArgumentException('Le fichier OPML ne doit pas contenir de DOCTYPE ou d’entités.');
        }

        $previous = libxml_use_internal_errors(true);
        try {
            $document = new \DOMDocument();
            if (!$document->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT)
                || !$document->documentElement instanceof \DOMElement
                || 'opml' !== strtolower($document->documentElement->localName)) {
                throw new \InvalidArgumentException('Le fichier n’est pas un OPML valide.');
            }

            $subscriptions = [];
            $xpath = new \DOMXPath($document);
            foreach ($xpath->query('/*[local-name()="opml"]/*[local-name()="body"]//*[local-name()="outline"][@xmlUrl]') ?: [] as $outline) {
                if (!$outline instanceof \DOMElement) {
                    continue;
                }

                $url = trim($outline->getAttribute('xmlUrl'));
                if ('' !== $url) {
                    $subscriptions[] = new ImportedSubscription($url, $this->closestFolderName($outline));
                }
            }

            return $subscriptions;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private function closestFolderName(\DOMElement $outline): ?string
    {
        for ($parent = $outline->parentNode; $parent instanceof \DOMElement; $parent = $parent->parentNode) {
            if ('outline' !== $parent->localName || $parent->hasAttribute('xmlUrl')) {
                continue;
            }

            $name = trim($parent->getAttribute('text'));

            return '' === $name ? null : $name;
        }

        return null;
    }
}
