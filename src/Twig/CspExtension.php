<?php

declare(strict_types=1);

namespace App\Twig;

use App\Security\CspNonce;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class CspExtension extends AbstractExtension
{
    public function __construct(private readonly CspNonce $nonce)
    {
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('csp_nonce', $this->nonce->value(...))];
    }
}
