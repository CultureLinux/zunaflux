<?php

declare(strict_types=1);

namespace App\Application\Administration;

use Doctrine\DBAL\Connection;

final readonly class RegistrationLimit
{
    public function __construct(private Connection $connection, private string $registrationLimitEnv) {}

    public function environmentLimit(): ?int
    {
        if ('' === $this->registrationLimitEnv) return null;
        if (!ctype_digit($this->registrationLimitEnv) || (int) $this->registrationLimitEnv < 1 || (int) $this->registrationLimitEnv > 2147483647 || (string) (int) $this->registrationLimitEnv !== $this->registrationLimitEnv) {
            throw new \InvalidArgumentException('ZUNAFLUX_MAX_ACCOUNTS doit être un entier positif ou vide.');
        }
        return (int) $this->registrationLimitEnv;
    }

    public function effectiveLimit(bool $lock = false): ?int
    {
        $stored = $this->connection->fetchAssociative('SELECT account_limit FROM registration_settings WHERE id = 1'.($lock ? ' FOR UPDATE' : ''));
        if (false === $stored) throw new \RuntimeException('Configuration des inscriptions introuvable.');
        return $this->environmentLimit() ?? (null === $stored['account_limit'] ? null : (int) $stored['account_limit']);
    }

    public function isFull(bool $lock = false): bool
    {
        $limit = $this->effectiveLimit($lock);
        return null !== $limit && (int) $this->connection->fetchOne('SELECT COUNT(*) FROM app_user') >= $limit;
    }

    public function update(?int $limit): void
    {
        if (null !== $this->environmentLimit()) throw new \DomainException('La limite est définie par l’environnement.');
        if (null !== $limit && ($limit < 1 || $limit > 2147483647)) throw new \InvalidArgumentException('La limite doit être comprise entre 1 et 2147483647.');
        $this->connection->executeStatement('UPDATE registration_settings SET account_limit = ? WHERE id = 1', [$limit]);
    }
}
