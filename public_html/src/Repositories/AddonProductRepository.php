<?php

declare(strict_types=1);

namespace App\Repositories;

use Doctrine\DBAL\Connection;

final class AddonProductRepository
{
    public function __construct(private Connection $db)
    {
    }

    public function listByEventId(int $eventId): array
    {
        return $this->db->fetchAllAssociative(
            'SELECT * FROM addon_products WHERE event_id = ? ORDER BY id ASC',
            [$eventId]
        );
    }

    public function findById(int $id): ?array
    {
        $row = $this->db->fetchAssociative(
            'SELECT * FROM addon_products WHERE id = ? LIMIT 1',
            [$id]
        );

        return $row ?: null;
    }

    public function create(array $data): int
    {
        $this->db->insert('addon_products', $data);
        return (int) $this->db->lastInsertId();
    }

    public function update(int $id, array $data): void
    {
        $this->db->update('addon_products', $data, ['id' => $id]);
    }
}

