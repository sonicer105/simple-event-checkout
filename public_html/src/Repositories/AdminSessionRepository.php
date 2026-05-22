<?php

declare(strict_types=1);

namespace App\Repositories;

use Doctrine\DBAL\Connection;

final class AdminSessionRepository
{
    public function __construct(private Connection $db)
    {
    }

    public function create(int $adminId, string $tokenHash, string $expiresAt): void
    {
        $this->db->insert('admin_sessions', [
            'admin_id' => $adminId,
            'token_hash' => $tokenHash,
            'expires_at' => $expiresAt,
        ]);
    }

    public function findByToken(string $tokenHash): ?array
    {
        $row = $this->db->fetchAssociative(
            'SELECT * FROM admin_sessions WHERE token_hash = ? LIMIT 1',
            [$tokenHash]
        );

        return $row ?: null;
    }

    public function deleteByToken(string $tokenHash): void
    {
        $this->db->delete('admin_sessions', ['token_hash' => $tokenHash]);
    }
}
