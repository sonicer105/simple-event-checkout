<?php

declare(strict_types=1);

namespace App\Repositories;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;

final class LoginLockoutRepository
{
    public function __construct(private Connection $db)
    {
    }

    public function listAll(): array
    {
        return $this->db->fetchAllAssociative(
            'SELECT * FROM login_lockouts ORDER BY (banned_until IS NULL) ASC, banned_until DESC, last_attempt_at DESC, id DESC'
        );
    }

    public function findByIp(string $ip): ?array
    {
        $row = $this->db->fetchAssociative(
            'SELECT * FROM login_lockouts WHERE ip = ? LIMIT 1',
            [$ip]
        );

        return $row ?: null;
    }

    public function registerAttempt(string $ip, int $windowSeconds, int $maxAttempts, int $banSeconds): void
    {
        $now = new DateTimeImmutable();
        $existing = $this->findByIp($ip);

        if (!$existing) {
            $this->db->insert('login_lockouts', [
                'ip' => $ip,
                'attempts' => 1,
                'banned_until' => null,
                'last_attempt_at' => $now->format('Y-m-d H:i:s'),
            ]);
            return;
        }

        $lastAttempt = new DateTimeImmutable($existing['last_attempt_at']);
        $attempts = (int) $existing['attempts'];

        if ($lastAttempt->getTimestamp() + $windowSeconds < $now->getTimestamp()) {
            $attempts = 0;
        }

        $attempts++;
        $bannedUntil = null;

        if ($attempts >= $maxAttempts) {
            $bannedUntil = $now->modify('+' . $banSeconds . ' seconds')->format('Y-m-d H:i:s');
        }

        $this->db->update('login_lockouts', [
            'attempts' => $attempts,
            'banned_until' => $bannedUntil,
            'last_attempt_at' => $now->format('Y-m-d H:i:s'),
        ], ['ip' => $ip]);
    }

    public function clear(string $ip): void
    {
        $this->db->delete('login_lockouts', ['ip' => $ip]);
    }
}
