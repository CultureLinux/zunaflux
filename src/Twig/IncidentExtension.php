<?php

declare(strict_types=1);

namespace App\Twig;

use App\Application\Notification\IncidentMessage;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Symfony\Contracts\Translation\TranslatorInterface;

final class IncidentExtension extends AbstractExtension
{
    public function __construct(private readonly IncidentMessage $message, private readonly TranslatorInterface $translator) {}

    public function getFilters(): array
    {
        return [new TwigFilter('incident_description', $this->describe(...))];
    }

    public function describe(string $message): string
    {
        $description = $this->message->describe($message);
        if (preg_match('/HTTP (\d{3})/', $description, $matches) && '429' !== $matches[1]) {
            return $this->translator->trans(str_replace($matches[1], '%code%', $description), ['%code%' => $matches[1]]);
        }

        return $this->translator->trans($description);
    }
}
