<?php

declare(strict_types=1);

namespace App\Repositories;

use Doctrine\DBAL\Connection;

final class PurchaseTicketRepository
{
    public function __construct(private Connection $db)
    {
    }

    public function listByPurchaseId(int $purchaseId): array
    {
        return $this->db->fetchAllAssociative(
            'SELECT pt.*, e.name AS event_name, ev.name AS variation_name
             FROM purchase_tickets pt
             JOIN events e ON e.id = pt.event_id
             LEFT JOIN event_variations ev ON ev.id = pt.variation_id
             WHERE pt.purchase_id = ?
             ORDER BY pt.id ASC',
            [$purchaseId]
        );
    }

    public function create(int $purchaseId, int $eventId, ?int $variationId, int $unitPriceCents, string $qrToken): int
    {
        $this->db->insert('purchase_tickets', [
            'purchase_id' => $purchaseId,
            'event_id' => $eventId,
            'variation_id' => $variationId,
            'unit_price_cents' => $unitPriceCents,
            'qr_token' => $qrToken,
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function findByQrToken(string $qrToken): ?array
    {
        $qrToken = trim($qrToken);
        if ($qrToken === '') {
            return null;
        }

        $row = $this->db->fetchAssociative(
            'SELECT
                pt.*,
                p.email AS purchase_email,
                e.name AS event_name,
                e.slug AS event_slug,
                ev.name AS variation_name
             FROM purchase_tickets pt
             JOIN purchases p ON p.id = pt.purchase_id
             JOIN events e ON e.id = pt.event_id
             LEFT JOIN event_variations ev ON ev.id = pt.variation_id
             WHERE pt.qr_token = ?
             LIMIT 1',
            [$qrToken]
        );

        return $row ?: null;
    }

    public function markCheckedIn(int $purchaseTicketId, int $adminId): void
    {
        // Make check-in idempotent: don't overwrite if already checked in.
        $this->db->executeStatement(
            'UPDATE purchase_tickets
             SET checked_in_at = COALESCE(checked_in_at, CURRENT_TIMESTAMP),
                 checked_in_by_admin_id = COALESCE(checked_in_by_admin_id, ?)
             WHERE id = ?',
            [$adminId, $purchaseTicketId]
        );
    }

    public function markRefundedByPurchaseId(int $purchaseId): void
    {
        $this->db->executeStatement(
            'UPDATE purchase_tickets
             SET refunded_at = COALESCE(refunded_at, CURRENT_TIMESTAMP)
             WHERE purchase_id = ?',
            [$purchaseId]
        );
    }

    public function findById(int $id): ?array
    {
        $id = (int) $id;
        if ($id <= 0) {
            return null;
        }

        $row = $this->db->fetchAssociative(
            'SELECT
                pt.*,
                p.email AS purchase_email,
                e.name AS event_name,
                e.slug AS event_slug,
                ev.name AS variation_name
             FROM purchase_tickets pt
             JOIN purchases p ON p.id = pt.purchase_id
             JOIN events e ON e.id = pt.event_id
             LEFT JOIN event_variations ev ON ev.id = pt.variation_id
             WHERE pt.id = ?
             LIMIT 1',
            [$id]
        );

        return $row ?: null;
    }

    public function listForCheckinLookup(?string $q, int $limit = 500): array
    {
        $limit = max(1, min(2000, $limit));
        $q = $q !== null ? trim($q) : '';

        $sql = 'SELECT
                    pt.id,
                    pt.purchase_id,
                    pt.event_id,
                    pt.variation_id,
                    pt.checked_in_at,
                    pt.refunded_at,
                    pt.created_at,
                    p.email AS purchase_email,
                    e.name AS event_name,
                    ev.name AS variation_name
                FROM purchase_tickets pt
                JOIN purchases p ON p.id = pt.purchase_id
                JOIN events e ON e.id = pt.event_id
                LEFT JOIN event_variations ev ON ev.id = pt.variation_id';

        $params = [];
        if ($q != '') {
            $like = '%' . $q . '%';
            $sql .= ' WHERE (
                        p.email LIKE ? OR
                        e.name LIKE ? OR
                        ev.name LIKE ? OR
                        EXISTS (
                            SELECT 1
                            FROM purchase_ticket_modifiers ptm
                            JOIN event_modifiers em ON em.id = ptm.modifier_id
                            WHERE ptm.purchase_ticket_id = pt.id
                              AND (em.name LIKE ? OR ptm.value LIKE ?)
                        )
                    )';
            $params = [$like, $like, $like, $like, $like];
        }

        $sql .= ' ORDER BY pt.id DESC LIMIT ' . $limit;

        return $this->db->fetchAllAssociative($sql, $params);
    }

}
