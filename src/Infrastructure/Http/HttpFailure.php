<?php

declare(strict_types=1);

namespace App\Infrastructure\Http;

enum HttpFailure: string
{
    /** DNS, connection, TLS or timeout error. */
    case Transport = 'transport';
    /** The decoded body exceeds the allowed size. */
    case TooLarge = 'too_large';
    case TooManyRedirects = 'too_many_redirects';
    /** A Location header that cannot be resolved to a usable absolute address. */
    case BadRedirect = 'bad_redirect';
}
