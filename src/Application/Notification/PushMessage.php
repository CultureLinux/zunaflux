<?php

declare(strict_types=1);

namespace App\Application\Notification;

use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class PushMessage
{
    public function __construct(private TranslatorInterface $translator) {}

    public function articles(int $count, string $locale): string
    {
        $message = 1 === $count ? '%count% nouvel article dans vos sources.' : '%count% nouveaux articles dans vos sources.';

        return $this->translator->trans($message, ['%count%' => $count], locale: $locale);
    }

    public function error(string $locale): string
    {
        return $this->translator->trans('Une source rencontre une erreur de synchronisation.', locale: $locale);
    }

    public function test(string $locale): string
    {
        return $this->translator->trans('Ceci est une notification de test Zunaflux.', locale: $locale);
    }
}
