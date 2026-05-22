<?php

declare(strict_types=1);

namespace App\Repositories;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;

final class CartRepository
{
    public function __construct(private Connection $db)
    {
    }

    public function findBySessionId(string $sessionId): ?array
    {
        $row = $this->db->fetchAssociative(
            'SELECT * FROM carts WHERE session_id = ? LIMIT 1',
            [$sessionId]
        );

        return $row ?: null;
    }

    public function create(string $sessionId, int $expiresDays = 14, int $reserveSeconds = 3600): int
    {
        $now = new DateTimeImmutable();
        $reservedUntil = $now->modify('+' . $reserveSeconds . ' seconds')->format('Y-m-d H:i:s');
        $expiresAt = $now->modify('+' . $expiresDays . ' days')->format('Y-m-d H:i:s');

        $this->db->insert('carts', [
            'session_id' => $sessionId,
            'reserved_until' => $reservedUntil,
            'expires_at' => $expiresAt,
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function touch(int $cartId, int $expiresDays = 14, int $reserveSeconds = 3600): void
    {
        $now = new DateTimeImmutable();
        $reservedUntil = $now->modify('+' . $reserveSeconds . ' seconds')->format('Y-m-d H:i:s');
        $expiresAt = $now->modify('+' . $expiresDays . ' days')->format('Y-m-d H:i:s');

        $this->db->update('carts', [
            'reserved_until' => $reservedUntil,
            'expires_at' => $expiresAt,
        ], ['id' => $cartId]);
    }

    public function delete(int $cartId): void
    {
        $this->db->delete('carts', ['id' => $cartId]);
    }
}

