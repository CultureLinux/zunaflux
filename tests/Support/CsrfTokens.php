<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/** Reads a CSRF token bound to the browser's session, for routes whose form is not rendered in the page under test. */
trait CsrfTokens
{
    private function csrfToken(KernelBrowser $client, string $tokenId): string
    {
        $client->disableReboot();
        $client->request('GET', '/preferences');
        $request = $client->getRequest();
        $stack = $client->getContainer()->get('request_stack');
        $stack->push($request);
        try {
            $value = $client->getContainer()->get('security.csrf.token_manager')->getToken($tokenId)->getValue();
            $request->getSession()->save();
        } finally {
            $stack->pop();
        }

        return $value;
    }
}
