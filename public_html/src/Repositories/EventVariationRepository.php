<?php

declare(strict_types=1);

namespace App\Repositories;

use Doctrine\DBAL\Connection;

final class EventVariationRepository
{
    public function __construct(private Connection $db)
    {
    }

    public function listByEventId(int $eventId): array
    {
        return $this->db->fetchAllAssociative(
            'SELECT * FROM event_variations WHERE event_id = ? ORDER BY sort_order ASC, id ASC',
            [$eventId]
        );
    }

    public function findById(int $id): ?array
    {
        $row = $this->db->fetchAssociative(
            'SELECT * FROM event_variations WHERE id = ? LIMIT 1',
            [$id]
        );

        return $row ?: null;
    }

    public function create(array $data): int
    {
        $this->db->insert('event_variations', $data);
        return (int) $this->db->lastInsertId();
    }

    public function update(int $id, array $data): void
    {
        $this->db->update('event_variations', $data, ['id' => $id]);
    }
}

