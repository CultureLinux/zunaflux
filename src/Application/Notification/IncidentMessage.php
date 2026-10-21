<?php

declare(strict_types=1);

namespace App\Application\Notification;

final class IncidentMessage
{
    public function describe(string $message): string
    {
        if (preg_match('/timed?\s*out|timeout|délai dépassé/i', $message)) return 'Le site a mis trop de temps à répondre.';
        if (preg_match('/returned HTTP\s+(\d{3})/i', $message, $matches)) {
            $code = (int) $matches[1];
            return match (true) {
                404 === $code || 410 === $code => sprintf('Le flux est introuvable sur le site (HTTP %d).', $code),
                401 === $code || 403 === $code => sprintf('Le site refuse l’accès au flux (HTTP %d).', $code),
                429 === $code => 'Le site limite les requêtes (HTTP 429).',
                $code >= 500 && $code <= 599 => sprintf('Le serveur du site rencontre une erreur (HTTP %d).', $code),
                default => sprintf('Le site a répondu avec une erreur HTTP %d.', $code),
            };
        }
        if (preg_match('/cannot be resolved|could not resolve|name or service not known/i', $message)) return 'Le nom de domaine du site ne peut pas être résolu.';
        if (preg_match('/certificate|ssl|tls/i', $message)) return 'La connexion sécurisée au site a échoué.';
        if (preg_match('/connection refused|failed to connect|could not connect/i', $message)) return 'La connexion au site a échoué.';
        if (preg_match('/not valid XML|DOCTYPE|entities/i', $message)) return 'Le contenu du flux est invalide ou non autorisé.';
        if (preg_match('/size limit|too large/i', $message)) return 'Le flux dépasse la taille autorisée.';
        if (preg_match('/private or reserved|forbidden port/i', $message)) return 'L’adresse du flux est bloquée pour des raisons de sécurité.';
        if (preg_match('/redirect/i', $message)) return 'La redirection du flux est invalide ou trop longue.';
        return 'Une erreur est survenue pendant la collecte du flux.';
    }
}
