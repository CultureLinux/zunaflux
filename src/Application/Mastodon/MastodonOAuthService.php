<?php

declare(strict_types=1);

namespace App\Application\Mastodon;

use App\Entity\MastodonAccount;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

final class MastodonOAuthService
{
    private const SCOPES = 'read:accounts write:statuses';
    private const SESSION_KEY = 'mastodon.oauth';

    public function __construct(private MastodonHttpClient $client, private AccessTokenCipher $cipher, private EntityManagerInterface $em, private string $publicBaseUrl) {}

    public function begin(SessionInterface $session, string $instanceUrl): string
    {
        $instance = MastodonInstance::fromInput($instanceUrl);
        $callback = rtrim($this->publicBaseUrl, '/').'/mastodon/callback';
        if (!str_starts_with($callback, 'https://')) throw new \RuntimeException('ZUNAFLUX_PUBLIC_BASE_URL doit être une origine HTTPS publique.');
        $app = $this->client->request($instance, 'POST', '/api/v1/apps', ['client_name' => 'Zunaflux', 'redirect_uris' => $callback, 'scopes' => self::SCOPES, 'website' => rtrim($this->publicBaseUrl, '/')]);
        if (!isset($app['client_id'], $app['client_secret']) || !is_string($app['client_id']) || !is_string($app['client_secret'])) throw new \RuntimeException('Réponse d’inscription Mastodon invalide.');
        $state = bin2hex(random_bytes(32)); $verifier = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $session->set(self::SESSION_KEY, ['instance' => $instance->origin(), 'client_id' => $app['client_id'], 'client_secret' => $app['client_secret'], 'state' => $state, 'verifier' => $verifier]);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        return $instance->endpoint('/oauth/authorize').'?'.http_build_query(['response_type' => 'code', 'client_id' => $app['client_id'], 'redirect_uri' => $callback, 'scope' => self::SCOPES, 'state' => $state, 'code_challenge' => $challenge, 'code_challenge_method' => 'S256'], '', '&', PHP_QUERY_RFC3986);
    }

    public function complete(User $user, SessionInterface $session, string $state, string $code): MastodonAccount
    {
        $flow = $session->remove(self::SESSION_KEY);
        if (!is_array($flow) || !isset($flow['state'], $flow['instance'], $flow['client_id'], $flow['client_secret'], $flow['verifier']) || !is_string($flow['state']) || !hash_equals($flow['state'], $state) || '' === $code) throw new \RuntimeException('La connexion Mastodon a expiré. Recommencez.');
        $instance = MastodonInstance::fromInput((string) $flow['instance']);
        $token = $this->client->request($instance, 'POST', '/oauth/token', ['grant_type' => 'authorization_code', 'code' => $code, 'client_id' => $flow['client_id'], 'client_secret' => $flow['client_secret'], 'redirect_uri' => rtrim($this->publicBaseUrl, '/').'/mastodon/callback', 'code_verifier' => $flow['verifier']]);
        if (!isset($token['access_token']) || !is_string($token['access_token'])) throw new \RuntimeException('Mastodon n’a pas fourni de jeton.');
        $profile = $this->client->request($instance, 'GET', '/api/v1/accounts/verify_credentials', [], $token['access_token']);
        foreach (['id', 'acct', 'display_name'] as $key) if (!isset($profile[$key]) || !is_string($profile[$key])) throw new \RuntimeException('Profil Mastodon invalide.');
        $account = $this->em->getRepository(MastodonAccount::class)->findOneBy(['user' => $user]);
        $encrypted = $this->cipher->encrypt($token['access_token']);
        if (!$account instanceof MastodonAccount) { $account = new MastodonAccount($user, $instance->origin(), $profile['id'], $profile['acct'], $profile['display_name'], isset($profile['avatar_static']) && is_string($profile['avatar_static']) ? $profile['avatar_static'] : null, $encrypted); $this->em->persist($account); } else { $account->replace($instance->origin(), $profile['id'], $profile['acct'], $profile['display_name'], isset($profile['avatar_static']) && is_string($profile['avatar_static']) ? $profile['avatar_static'] : null, $encrypted); }
        $this->em->flush(); return $account;
    }
}
