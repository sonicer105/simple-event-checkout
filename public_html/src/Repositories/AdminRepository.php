<?php

declare(strict_types=1);

namespace App\Repositories;

use Doctrine\DBAL\Connection;

final class AdminRepository
{
    public function __construct(private Connection $db)
    {
    }

    public function listAll(): array
    {
        return $this->db->fetchAllAssociative(
            'SELECT * FROM admins ORDER BY id ASC'
        );
    }

    public function update(int $adminId, array $data): void
    {
        if ($adminId <= 0) {
            return;
        }
        if (!$data) {
            return;
        }

        $this->db->update('admins', $data, ['id' => $adminId]);
    }

    public function delete(int $adminId): void
    {
        if ($adminId <= 0) {
            return;
        }

        $this->db->delete('admins', ['id' => $adminId]);
    }

    public function findById(int $id): ?array
    {
        $row = $this->db->fetchAssociative(
            'SELECT * FROM admins WHERE id = ? LIMIT 1',
            [$id]
        );

        return $row ?: null;
    }

    public function findByUsername(string $username): ?array
    {
        $row = $this->db->fetchAssociative(
            'SELECT * FROM admins WHERE username = ? LIMIT 1',
            [$username]
        );

        return $row ?: null;
    }

    public function create(string $username, string $email, string $passwordHash, string $role = 'full'): int
    {
        $this->db->insert('admins', [
            'username' => $username,
            'email' => $email,
            'role' => $role,
            'password_hash' => $passwordHash,
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function markLogin(int $adminId): void
    {
        $this->db->update('admins', [
            'last_login_at' => date('Y-m-d H:i:s'),
        ], ['id' => $adminId]);
    }

    public function updateTotpSecret(int $adminId, ?string $secret, bool $enabled): void
    {
        $this->db->update('admins', [
            'totp_secret' => $secret,
            'app_2fa_enabled' => $enabled ? 1 : 0,
        ], ['id' => $adminId]);
    }
}
