<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Contracts\Service\ResetInterface;

/** One unguessable value per request, shared by the CSP header and the inline scripts that carry it. */
final class CspNonce implements ResetInterface
{
    private ?string $value = null;

    public function value(): string
    {
        return $this->value ??= base64_encode(random_bytes(16));
    }

    public function reset(): void
    {
        $this->value = null;
    }
}
