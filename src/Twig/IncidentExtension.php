<?php

declare(strict_types=1);

namespace App\Twig;

use App\Application\Notification\IncidentMessage;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

final class IncidentExtension extends AbstractExtension
{
    public function __construct(private readonly IncidentMessage $message) {}

    public function getFilters(): array
    {
        return [new TwigFilter('incident_description', $this->message->describe(...))];
    }
}
