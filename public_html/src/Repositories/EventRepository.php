<?php

declare(strict_types=1);

namespace App\Repositories;

use Doctrine\DBAL\Connection;

final class EventRepository
{
    public function __construct(private Connection $db)
    {
    }

    public function listAll(): array
    {
        return $this->db->fetchAllAssociative(
            'SELECT * FROM events ORDER BY start_time IS NULL, start_time ASC, id DESC'
        );
    }

    public function listPublicIndex(): array
    {
        return $this->db->fetchAllAssociative(
            "SELECT * FROM events WHERE status = 'published' ORDER BY start_time IS NULL, start_time ASC, id DESC"
        );
    }

    public function findById(int $id): ?array
    {
        $row = $this->db->fetchAssociative(
            'SELECT * FROM events WHERE id = ? LIMIT 1',
            [$id]
        );

        return $row ?: null;
    }

    public function findPublicBySlug(string $slug): ?array
    {
        $row = $this->db->fetchAssociative(
            "SELECT * FROM events WHERE slug = ? AND status IN ('published', 'unlisted', 'archived') LIMIT 1",
            [$slug]
        );

        return $row ?: null;
    }

    public function create(array $data): int
    {
        $this->db->insert('events', $data);
        return (int) $this->db->lastInsertId();
    }

    public function update(int $id, array $data): void
    {
        $this->db->update('events', $data, ['id' => $id]);
    }

    public function listTopByTickets(int $limit = 10): array
    {
        $limit = max(1, min(50, $limit));

        return $this->db->fetchAllAssociative(
            "SELECT
                e.*,
                COUNT(pt.id) AS ticket_count,
                SUM(CASE WHEN pt.checked_in_at IS NOT NULL THEN 1 ELSE 0 END) AS picked_up_count
             FROM events e
             JOIN purchase_tickets pt ON pt.event_id = e.id AND pt.refunded_at IS NULL
             JOIN purchases p ON p.id = pt.purchase_id AND p.payment_status = 'paid'
             GROUP BY e.id
             ORDER BY ticket_count DESC, e.start_time IS NULL, e.start_time ASC, e.id DESC
             LIMIT " . $limit
        );
    }

    public function listWeeklyTicketCountsForEvent(int $eventId, \DateTimeInterface $from, \DateTimeInterface $to): array
    {
        $eventId = (int) $eventId;
        if ($eventId <= 0) {
            return [];
        }

        return $this->db->fetchAllAssociative(
            "SELECT
                DATE(DATE_SUB(pt.created_at, INTERVAL WEEKDAY(pt.created_at) DAY)) AS week_start,
                COUNT(pt.id) AS ticket_count,
                SUM(pt.unit_price_cents + IFNULL(a.addon_cents, 0)) AS revenue_cents
             FROM purchase_tickets pt
             JOIN purchases p ON p.id = pt.purchase_id AND p.payment_status = 'paid'
             LEFT JOIN (
                SELECT purchase_ticket_id, SUM(quantity * unit_price_cents) AS addon_cents
                FROM purchase_ticket_addons
                GROUP BY purchase_ticket_id
             ) a ON a.purchase_ticket_id = pt.id
             WHERE pt.event_id = ?
               AND pt.refunded_at IS NULL
               AND pt.created_at >= ?
               AND pt.created_at < ?
             GROUP BY week_start
             ORDER BY week_start ASC",
            [
                $eventId,
                $from->format('Y-m-d H:i:s'),
                $to->format('Y-m-d H:i:s'),
            ]
        );
    }

}
