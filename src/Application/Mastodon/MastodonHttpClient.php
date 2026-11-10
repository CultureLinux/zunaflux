<?php

declare(strict_types=1);

namespace App\Application\Mastodon;

class MastodonHttpClient
{
    /** @return array<string, mixed> */
    public function request(MastodonInstance $instance, string $method, string $path, array $form = [], ?string $accessToken = null): array
    {
        $url = $instance->endpoint($path);
        $host = (string) parse_url($url, PHP_URL_HOST);
        $ips = gethostbynamel($host) ?: [];
        if ([] === $ips || array_filter($ips, fn (string $ip): bool => false === filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE))) {
            throw new \RuntimeException('L’instance Mastodon ne résout pas vers une adresse publique.');
        }
        if (!function_exists('curl_init')) throw new \RuntimeException('L’extension cURL est requise pour Mastodon.');
        $curl = curl_init($url);
        if (false === $curl) throw new \RuntimeException('La connexion à Mastodon est indisponible.');
        $headers = ['Accept: application/json'];
        if (null !== $accessToken) $headers[] = 'Authorization: Bearer '.$accessToken;
        if ([] !== $form) { $headers[] = 'Content-Type: application/x-www-form-urlencoded'; }
        try {
            curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers, CURLOPT_POSTFIELDS => [] === $form ? null : http_build_query($form, '', '&', PHP_QUERY_RFC3986), CURLOPT_FOLLOWLOCATION => false, CURLOPT_MAXREDIRS => 0, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 15, CURLOPT_RESOLVE => [$host.':443:'.implode(',', $ips)]]);
            $body = curl_exec($curl); $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            if (false === $body) throw new \RuntimeException('La connexion à Mastodon a échoué.');
        } finally { curl_close($curl); }
        try { $data = json_decode($body, true, 32, JSON_THROW_ON_ERROR); } catch (\JsonException) { throw new \RuntimeException('Réponse Mastodon invalide.'); }
        if ($status < 200 || $status >= 300 || !is_array($data)) throw new MastodonApiException($status);

        return $data;
    }
}
